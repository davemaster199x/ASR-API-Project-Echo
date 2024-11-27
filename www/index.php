<?php

/**
 * This script receives the request from the client and processes it.
 */

	require_once '../config.php';

// Set JSON content type header
	header( 'Content-Type: application/json' );

// Establish DB connection
	try {
		$pdo = new PDO(
			"{$config['db']['type']}:host={$config['db']['host']};dbname={$config['db']['name']}",
			$config['db']['user'],
			$config['db']['pass']
		);
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	} catch ( PDOException $e ) {
		error_log( "Database Error: " . $e->getMessage() );
		
	// Return generic error to client
		http_response_code( 500 );
		die(json_encode( ['error' => 'An internal error occurred. Please try again later.'] ));
	}

// Read the username/password credentials from the HTTP headers
	if ( !isset( $_SERVER['PHP_AUTH_USER'] ) || !isset( $_SERVER['PHP_AUTH_PW'] )) {
		http_response_code( 401 );
		die(json_encode( ['error' => 'Authentication required'] ));
	}

// Validate the user
	$stmt = $pdo->prepare( "SELECT user_id, password FROM user WHERE user = ?" );
	$stmt->execute( [$_SERVER['PHP_AUTH_USER']] );
	$user = $stmt->fetch( PDO::FETCH_ASSOC );

	if ( !$user || !password_verify( $_SERVER['PHP_AUTH_PW'], $user['password'] )) {
		http_response_code( 401 );
		die( json_encode( ['error' => 'Invalid credentials'] ));
	}

	$user_id = $user['user_id'];

// Parse the request
	$input = json_decode( file_get_contents( 'php://input' ), true);
	if ( !$input || !isset( $input['request_type'] )) {
		http_response_code( 400 );
		die(json_encode( ['error' => 'Invalid input'] ));
	}

	$request_type = $input['request_type'];

	switch ( $request_type ) {
		case 'new':
		// This is a new job
			if ( !isset( $input['postback_url'] ) || !isset( $input['audio'] )) {
				http_response_code( 400 );
				die( json_encode( ['error' => 'Missing required fields'] ));
			}
			
		// Decode and validate audio
			$audio_data = base64_decode( $input['audio'] );
			if ( !$audio_data ) {
				http_response_code( 400 );
				die(json_encode( ['error' => 'Invalid audio data'] ));
			}
			
		// Check file size
			$file_size = strlen( $audio_data ) / 1024 / 1024; // Convert to MB
			if ( $file_size > $config['limits']['max_file_size'] ) {
				http_response_code( 400 );
				die( json_encode( ['error' => 'File size exceeds limit'] ));
			}
			
		// Create hash and store file
			$hash = hash( 'sha256', $audio_data );
			$file_path = '../' . $config['paths']['files'] . substr( $hash, 0, 2 ) . '/' . $hash;
			
			if ( !is_dir( dirname( $file_path ))) {
				mkdir( dirname( $file_path ), 0777, true );
			}

			if ( !file_put_contents( $file_path, $audio_data )) {
				http_response_code( 500 );
				die( json_encode( ['error' => 'Failed to store file'] ));
			}

		// Start transaction
			try {
			// Create file record
				$stmt = $pdo->prepare( "INSERT INTO file (hash, type) VALUES (?, 'audio/wav')" );
				$stmt->execute( [ $hash ] );
				$file_id = $pdo->lastInsertId();

			// Create job record
				$stmt = $pdo->prepare( "INSERT INTO job (user_id, file_id, postback_url, status) VALUES (?, ?, ?, 'New')" );
				$stmt->execute( [ $user_id, $file_id, $input['postback_url'] ] );
				$job_id = $pdo->lastInsertId();

				echo json_encode( ['job_id' => $job_id] );
			} catch ( Exception $e ) {
				http_response_code( 500 );
				die( json_encode( ['error' => 'Failed to create job'] ));
			}
			break;

		case 'status':
		// Checking the status of an existing job
			if ( !isset( $input['job_id'] )) {
				http_response_code( 400 );
				die( json_encode( ['error' => 'Missing job_id'] ));
			}

			$stmt = $pdo->prepare( "SELECT status FROM job WHERE job_id = ? AND user_id = ?" );
			$stmt->execute( [ $input['job_id'], $user_id ] );
			$job = $stmt->fetch( PDO::FETCH_ASSOC );

			if ( !$job ) {
				http_response_code( 404 );
				die(json_encode( ['error' => 'Job not found'] ));
			}

			echo json_encode([
				'job_id' => $input['job_id'],
				'status' => $job['status']
			]);
			break;

		case 'cancel':
		// Cancel an existing job
			if ( !isset( $input['job_id'] )) {
				http_response_code( 400 );
				die( json_encode( ['error' => 'Missing job_id'] ));
			}

			$stmt = $pdo->prepare( "UPDATE job SET status = 'Canceled' WHERE job_id = ? AND user_id = ? AND status = 'New'" );
			$stmt->execute( [ $input['job_id'], $user_id ]);

			if ( $stmt->rowCount() === 0 ) {
				http_response_code( 400 );
				die( json_encode( ['error' => 'Job cannot be canceled'] ));
			}

			echo json_encode([
				'job_id' => $input['job_id'],
				'status' => 'Canceled'
			]);
			break;

		case 'replay':
		// Replay a previously completed job
			if ( !isset($input['job_id'] )) {
				http_response_code( 400 );
				die( json_encode( ['error' => 'Missing job_id'] ));
			}
			try {
			// Get original job details
				$stmt = $pdo->prepare( "SELECT file_id, postback_url FROM job WHERE job_id = ? AND user_id = ? AND status IN ('Completed', 'Canceled')" );
				$stmt->execute( [ $input['job_id'], $user_id ]);
				$original_job = $stmt->fetch( PDO::FETCH_ASSOC );

				if ( !$original_job ) {
					echo json_encode([
						'job_id' => $input['job_id'],
						'status' => 'New and Processing jobs cant be replayed'
					]);
				} else {
				// Create new job with same file
					$postback_url = isset( $input['postback_url'] ) ? 
					$input['postback_url'] : 
					$original_job['postback_url'];

					$stmt = $pdo->prepare(
					"UPDATE job 
							SET status = 'New', postback_url = ? 
							WHERE job_id = ? AND user_id = ?"
					);
					$stmt->execute( [ $postback_url, $input['job_id'], $user_id, ]);

					echo json_encode([
						'job_id' => $input['job_id'],
						'status' => 'New'
					]);
				}
			} catch ( Exception $e ) {
				http_response_code( 400 );
				die( json_encode( ['error' => $e->getMessage()] ));
			}
			break;

		default:
			http_response_code( 400 );
			die( json_encode( ['error' => 'Invalid request type'] ));
	}