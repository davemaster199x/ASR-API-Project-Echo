# ASR (Automated Speech Recognition) API

An API service that uses OpenAI's Whisper to process audio files and return transcriptions.

## Features

### Job Management
- Create new transcription jobs
- Check job status
- Cancel pending jobs
- Replay completed jobs
- Asynchronous processing

### Authentication
- Basic HTTP Authentication
- Secure password hashing
- User-specific job access

### File Handling
- Hash-based file storage
- File size validation
- Organized directory structure

### Audio Processing
- Uses Whisper CLI for transcription
- Supports English language processing
- Returns segmented transcriptions
- Provides full text and time-stamped segments

## Database Structure

### Tables
1. `user`
   - Manages user authentication
   - Stores hashed passwords
   - Tracks user creation and updates

2. `file`
   - Stores file metadata
   - Uses SHA-256 hashing
   - Tracks file types

3. `job`
   - Links users and files
   - Tracks job status
   - Stores transcription results
   - Manages postback URLs

## API Endpoints

All endpoints use POST method with JSON request body.

### 1. New Job
Creates a new transcription job
```json
{
    "request_type": "new",
    "postback_url": "https://your-callback-url",
    "audio": "[base64-encoded audio file]"
}
```

### 2. Status Check
Checks job status
```json
{
    "request_type": "status",
    "job_id": 123
}
```

### 3. Cancel Job
Cancels a pending job
```json
{
    "request_type": "cancel",
    "job_id": 123
}
```

### 4. Replay Job
Replays a completed job
```json
{
    "request_type": "replay",
    "job_id": 123,
    "postback_url": "https://new-callback-url" // Optional
}
```

## Response Format

### Success Response
```json
{
    "job_id": 123,
    "text": "Complete transcription text",
    "segments": [
        "Time-stamped segment 1",
        "Time-stamped segment 2"
    ]
}
```

### Error Response
```json
{
    "error": "Error description"
}
```

## Setup Requirements

1. **PHP Requirements**
   - PHP 7.4 or higher
   - PDO MySQL extension
   - CURL extension

2. **Database**
   - MySQL/MariaDB
   - Create database using provided schema

3. **OpenAI Whisper**
   - Whisper CLI installed
   - System PATH configured for Whisper

4. **File System**
   - Write permissions for file storage
   - Adequate storage space for audio files

## Configuration

1. Copy `config.sample.php` to `config.php`
2. Update database credentials
3. Set file storage path
4. Configure maximum file size
5. Set up cron job for processing

```php
$config = [
    'paths' => [
        'files' => '/path/to/storage/'
    ],
    'db' => [
        'type' => 'mysql',
        'host' => 'localhost',
        'name' => 'database_name',
        'user' => 'username',
        'pass' => 'password'
    ],
    'limits' => [
        'max_file_size' => 20 // MB
    ]
];
```

## Process Flow
1. Client submits audio file
2. System stores file and creates job
3. Cron job processes pending jobs
4. Whisper transcribes audio
5. Results sent to postback URL
6. Job marked as completed

## Security Features
- Password hashing
- User authentication
- File validation
- Safe error handling
- Process locking

## Error Handling
- Database connection errors
- File system errors
- Processing errors
- Authentication failures
- Input validation

## Logging
- Process status logging
- Error logging
- Job completion logging

## Files Structure
```
/
├── www/
│   └── index.php       # Main API endpoint
├── sql/
│   └── schema.sql      # Database schema
├── config.php          # Configuration
├── process.php         # Job processor
└── README.md          # Documentation
```