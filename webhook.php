<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configuration
$sendgridApiKey = getenv('SENDGRID_API_KEY');
$emailFrom = getenv('EMAIL_FROM') ?: 'voicemail@yourdomain.com';
$emailFromName = 'FreePBX Voicemail';
$defaultEmailRecipient = getenv('DEFAULT_EMAIL_RECIPIENT') ?: 'your-email@example.com';

// Logging function
function logMessage($message) {
    error_log(date('Y-m-d H:i:s') . " - " . $message);
}

// Extract metadata from name field
function extractMetadata($nameField) {
    if (preg_match('/METADATA:(.+)$/', $nameField, $matches)) {
        $metadataJson = base64_decode($matches[1]);
        return json_decode($metadataJson, true);
    }
    return null;
}

// SendGrid email function
function sendEmailViaSendGrid($to, $subject, $htmlBody, $textBody, $apiKey, $fromEmail, $fromName) {
    if (empty($apiKey)) {
        logMessage("ERROR: SendGrid API key not configured");
        return false;
    }
    
    $data = [
        'personalizations' => [[
            'to' => [['email' => $to]],
            'subject' => $subject
        ]],
        'from' => [
            'email' => $fromEmail,
            'name' => $fromName
        ],
        'content' => [
            ['type' => 'text/plain', 'value' => $textBody],
            ['type' => 'text/html', 'value' => $htmlBody]
        ]
    ];
    
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    if ($ch === false) {
         logMessage("ERROR: Failed to initialize cURL");
        return false;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        logMessage("CURL Error: $curlError");
        return false;
    }
    
    logMessage("SendGrid Response Code: $httpCode");
    
    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    } else {
        logMessage("SendGrid Error: $response");
        return false;
    }
}

// Build email content
function buildEmailContent($callerID, $mailbox, $timestamp, $transcriptionData) {
    $transcriptionText = $transcriptionData['transcription']['text'] ?? 'No transcription available';
    $language = $transcriptionData['transcription']['language'] ?? 'Unknown';
    $duration = $transcriptionData['duration_seconds'] ?? 'Unknown';
    $confidence = $transcriptionData['transcription']['confidence'] ?? 'N/A';
    $jobId = $transcriptionData['id'] ?? 'Unknown';
    
    $textBody = "VOICEMAIL TRANSCRIPTION\n\n";
    $textBody .= "From: $callerID\n";
    $textBody .= "Mailbox: $mailbox\n";
    $textBody .= "Received: $timestamp\n";
    $textBody .= "Duration: $duration seconds\n";
    $textBody .= "Language: $language\n\n";
    $textBody .= "TRANSCRIPTION:\n";
    $textBody .= wordwrap($transcriptionText, 70) . "\n\n";
    $textBody .= "Job ID: $jobId\n";
    
    $htmlBody = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body>";
    $htmlBody .= "<h2>Voicemail Transcription</h2>";
    $htmlBody .= "<p><strong>From:</strong> $callerID</p>";
    $htmlBody .= "<p><strong>Mailbox:</strong> $mailbox</p>";
    $htmlBody .= "<p><strong>Received:</strong> $timestamp</p>";
    $htmlBody .= "<p><strong>Duration:</strong> $duration seconds</p>";
    $htmlBody .= "<p><strong>Language:</strong> $language</p>";
    $htmlBody .= "<h3>Transcription:</h3>";
    $htmlBody .= "<p>" . nl2br(htmlspecialchars($transcriptionText)) . "</p>";
    $htmlBody .= "<p><small>Job ID: $jobId</small></p>";
    $htmlBody .= "</body></html>";
    
    return ['text' => $textBody, 'html' => $htmlBody];
}

// Main execution
header('Content-Type: application/json');

logMessage("=== Webhook Request Received ===");

try {
    // Get query parameters
    $emailRecipient = $_GET['email'] ?? $defaultEmailRecipient;
    $callerIDFromQuery = $_GET['caller_id'] ?? null;
    $mailboxFromQuery = $_GET['mailbox'] ?? null;
    
    // Get POST data
    $rawData = file_get_contents('php://input');
    
    if (empty($rawData)) {
        logMessage("WARNING: Empty request body");
        http_response_code(400);
        echo json_encode(['error' => 'Empty request body']);
        exit;
    }
    
    $webhookData = json_decode($rawData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("ERROR: JSON parse error - " . json_last_error_msg());
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
    
    $jobId = $webhookData['id'] ?? null;
    $status = $webhookData['status'] ?? 'Unknown';
    
    if (!$jobId) {
        logMessage("ERROR: No job ID");
        http_response_code(400);
        echo json_encode(['error' => 'No job ID']);
        exit;
    }
    
    logMessage("Job ID: $jobId | Status: $status");
    
    if ($status !== 'completed') {
        http_response_code(200);
        echo json_encode(['message' => 'Status received', 'status' => $status]);
        exit;
    }
    
    // Extract metadata
    $nameField = $webhookData['name'] ?? '';
    $metadata = extractMetadata($nameField);
    
    $callerID = $metadata['caller_id'] ?? ($callerIDFromQuery ?? 'Unknown');
    $mailbox = $metadata['mailbox'] ?? ($mailboxFromQuery ?? 'Unknown');
    $timestamp = $metadata['timestamp'] ?? date('Y-m-d H:i:s');
    $emailRecipient = $metadata['email'] ?? $emailRecipient;
    
    logMessage("Sending to: $emailRecipient");
    
    // Build and send email
    $emailContent = buildEmailContent($callerID, $mailbox, $timestamp, $webhookData);
    $subject = "Voicemail from $callerID";
    
    $emailSent = sendEmailViaSendGrid(
        $emailRecipient,
        $subject,
        $emailContent['html'],
        $emailContent['text'],
        $sendgridApiKey,
        $emailFrom,
        $emailFromName
    );
    
    if ($emailSent) {
        logMessage("✓ Email sent successfully");
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Email sent',
            'job_id' => $jobId
        ]);
    } else {
        logMessage("✗ Failed to send email");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Email failed',
            'job_id' => $jobId
        ]);
    }
    
} catch (Exception $e) {
    logMessage("EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

logMessage("=== Processing Complete ===");
