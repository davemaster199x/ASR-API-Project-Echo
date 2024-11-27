<?php
/**
 * Check for any jobs and process them.
 */

	require_once 'config.php';  // Direct include since it's in the same directory

// Set max memory limit to unlimited
	ini_set( 'memory_limit', '-1' );

	notify( 'info', 'Beginning process.' );

	// Make sure another instance isn't already running
	$lock_file = fopen( __DIR__ . '/process.pid', 'c' );
	$got_lock  = flock( $lock_file, LOCK_EX | LOCK_NB, $wouldblock );

	if ( $lock_file === FALSE || ( !$got_lock && !$wouldblock )) {
		notify( 'error', "Can't create lock file process.pid" );
		exit( 1 );
	} elseif ( !$got_lock && $wouldblock ) {
		notify( 'error', 'Another instance is already running; terminating.' );
		exit( 1 );
	}

	ftruncate( $lock_file, 0 );
	fwrite( $lock_file, getmypid() . "\n" );

	try {
	// Establish DB connection
		$pdo = new PDO(
			"{$config['db']['type']}:host={$config['db']['host']};dbname={$config['db']['name']}",
			$config['db']['user'],
			$config['db']['pass']
		);
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		// Get new jobs
		$stmt = $pdo->prepare(
    "SELECT j.*, f.hash 
			FROM job j
			JOIN file f ON j.file_id = f.file_id
			WHERE j.status = 'New' 
			ORDER BY j.created ASC"
		);
		$stmt->execute();
		$jobs = $stmt->fetchAll( PDO::FETCH_ASSOC );

		foreach ( $jobs as $job ) {
			try {
				notify( 'info', "Processing job {$job['job_id']}" );

			// Update status to Processing
				$stmt = $pdo->prepare(
					"UPDATE job SET status = 'Processing' WHERE job_id = ?"
				);
				$stmt->execute( [ $job['job_id'] ]);

			// Get file path
				$file_path = $config['paths']['files'] . 
						substr($job['hash'], 0, 2) . '/' . 
						$job['hash'];

				if ( !file_exists( $file_path )) {
					throw new Exception('Audio file not found');
				}

			// Execute whisper command
				$command    = "whisper {$file_path} --model tiny --language English";
				$output     = [];
				$return_var = 0;
		
			// Execute the command
				exec( $command, $output, $return_var );
		
				if ( $return_var !== 0 ) {
					throw new Exception( 'Whisper processing failed' );
				}

			// Format the segments
				$segments = [];
				$full_text = "";

				foreach ( $output as $line ) {
				// Extract text between the timestamps and append to segments
					if ( preg_match('/\[.*?\](.*?)$/', $line, $matches )) {
						$segment_text = trim( $matches[1] );
						$segments[]   = $segment_text;
						$full_text   .= $segment_text . ". ";
					}
				}

			// Create the formatted result
				$postback_data = [
					'job_id'   => $job['job_id'],
					'text'     => trim( $full_text ),
					'segments' => $segments
				];

			// Store result
				$stmt = $pdo->prepare(
					"UPDATE job 
					SET result = ?, status = 'Completed' 
					WHERE job_id = ?"
				);
				$stmt->execute( [ json_encode( $postback_data ), $job['job_id'] ]);


			// Send postback if URL exists
				if ( !empty( $job['postback_url'] )) {
					$curl = curl_init( $job['postback_url'] );
					curl_setopt_array( $curl, [
						CURLOPT_POST           => true,
						CURLOPT_RETURNTRANSFER => true,
						CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
						CURLOPT_POSTFIELDS     => json_encode( $postback_data )
					]);

					if ( !curl_exec( $curl )) {
						notify('error', "Postback failed for job {$job['job_id']}: " . 
							curl_error( $curl ));
					}
					curl_close( $curl );
				}

				notify( 'info', "Completed job {$job['job_id']}" );

			} catch ( Exception $e ) {
				notify( 'error', "Error processing job {$job['job_id']}: " . 
					$e->getMessage() );
			}
		}

	} catch ( Exception $e ) {
		notify( 'error', 'Process error: ' . $e->getMessage() );
	} finally {
	// Release lock
		flock( $lock_file, LOCK_UN );
		fclose( $lock_file );
		@unlink( __DIR__ . '/process.pid' );
	}

	function notify( $level, $text ) {
		global $config;

		echo date('Y-m-d H:i:s') . " - $text\n";

		if ( isset( $config['main']['log_level'] )) {
			if ( $config['main']['log_level'] == 'debug' ) {
				syslog( LOG_DEBUG, 'ASR PROCESSING: ' . $text );
			} elseif ( $level == 'info' && $config['main']['log_level'] != 'none' ) {
				syslog( LOG_INFO, 'ASR PROCESSING: ' . $text );
			} elseif ( $level == 'error' ) {
				syslog( LOG_ERR, 'ASR PROCESSING: ' . $text );
			}
		}
	}