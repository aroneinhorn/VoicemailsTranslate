<?php

/**
 * YiddishLabs Webhook Handler - Render Deployment
 * Receives transcription results and sends email
 */

// ==============================================
// CONFIGURATION
// ==============================================

// Email settings - UPDATE THESE
$emailFrom = 'voicemail@yourdomain.com';
$emailFromName = 'FreePBX Voicemail Transcription';
$defaultEmailRecipient = 'your-email@example.com'; // Fallback email

// Optional: Use environment variables in Render
if (getenv('EMAIL_FROM')) {
    $emailFrom = getenv('EMAIL_FROM');
}
if (getenv('DEFAULT_EMAIL_RECIPIENT')) {
    $defaultEmailRecipient = getenv('DEFAULT_EMAIL_RECIPIENT');
}

// ==============================================
// FUNCTIONS
// ==============================================

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message");
}

function extractMetadata($nameField) {
    // Try to extract metadata from name field
    if (preg_match('/METADATA:(.+)$/', $nameField, $matches)) {
        $metadataJson = base64_decode($matches[1]);
        return json_decode($metadataJson, true);
    }
    return null;
}

function sendEmailTranscription($to, $callerID, $mailbox, $timestamp, $transcriptionData, $emailFrom, $emailFromName) {
    
    $transcriptionText = $transcriptionData['transcription']['text'] ?? 'No transcription available';
    $language = $transcriptionData['transcription']['language'] ?? 'Unknown';
    $duration = $transcriptionData['duration_seconds'] ?? 'Unknown';
    $confidence = $transcriptionData['transcription']['confidence'] ?? 'N/A';
    $jobId = $transcriptionData['id'] ?? 'Unknown';
    $fileName = $transcriptionData['name'] ?? 'Unknown';
    
    // Extract just the descriptive part of the name (before METADATA)
    $displayName = preg_replace('/\s*\|?\s*METADATA:.+$/', '', $fileName);
    
    $subject = "Voicemail Transcription from $callerID";
    
    // Build plain text email body
    $emailBody = "=================================\n";
    $emailBody .= "VOICEMAIL TRANSCRIPTION\n";
    $emailBody .= "=================================\n\n";
    $emailBody .= "From: $callerID\n";
    $emailBody .= "Mailbox: $mailbox\n";
    $emailBody .= "Received: $timestamp\n";
    $emailBody .= "Duration: $duration seconds\n";
    $emailBody .= "Language: $language\n";
    $emailBody .= "Confidence: $confidence\n\n";
    $emailBody .= "=================================\n";
    $emailBody .= "TRANSCRIPTION:\n";
    $emailBody .= "=================================\n\n";
    $emailBody .= wordwrap($transcriptionText, 70) . "\n\n";
    $emailBody .= "=================================\n";
    $emailBody .= "Job ID: $jobId\n";
    $emailBody .= "=================================\n";
    
    // Build HTML email body
    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #4CAF50; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
            .header h2 { margin: 0; font-size: 24px; }
            .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; border-top: none; }
            .info-table { width: 100%; margin: 15px 0; }
            .info-row { padding: 8px 0; border-bottom: 1px solid #eee; }
            .info-label { font-weight: bold; color: #555; width: 120px; display: inline-block; }
            .info-value { color: #333; }
            .transcription { background-color: white; padding: 20px; margin: 20px 0; border-left: 4px solid #4CAF50; border-radius: 3px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .transcription h3 { margin-top: 0; color: #4CAF50; }
            .transcription-text { font-size: 16px; line-height: 1.8; color: #333; white-space: pre-wrap; }
            .footer { background-color: #f1f1f1; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 5px 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>📧 New Voicemail Transcription</h2>
            </div>
            <div class='content'>
                <div class='info-table'>
                    <div class='info-row'>
                        <span class='info-label'>From:</span>
                        <span class='info-value'>$callerID</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Mailbox:</span>
                        <span class='info-value'>$mailbox</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Received:</span>
                        <span class='info-value'>$timestamp</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Duration:</span>
                        <span class='info-value'>$duration seconds</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Language:</span>
                        <span class='info-value'>$language</span>
                    </div>
                    <div class='info-row'>
                        <span class='info-label'>Confidence:</span>
                        <span class='info-value'>$confidence</span>
                    </div>
                </div>
                
                <div class='transcription'>
                    <h3>Transcription</h3>
                    <div class='transcription-text'>" . nl2br(htmlspecialchars($transcriptionText)) . "</div>
                </div>
            </div>
            <div class='footer'>
                Job ID: $jobId
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Email headers
    $headers = "From: $emailFromName <$emailFrom>\r\n";
    $headers .= "Reply-To: $emailFrom\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"boundary456\"\r\n";
    
    // Build multipart email
    $message = "--boundary456\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $emailBody . "\r\n";
    $message .= "--boundary456\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $message .= $htmlBody . "\r\n";
    $message .= "--boundary456--";
    
    // Send email
    $success = @mail($to, $subject, $message, $headers);
    
    return $success;
}

// ==============================================
// MAIN EXECUTION
// ==============================================

// Set header for JSON response
header('Content-Type: application/json');

logMessage("=== Webhook Request Received ===");
logMessage("Method: " . $_SERVER['REQUEST_METHOD']);
logMessage("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'None'));

// Get query parameters
$emailRecipient = $_GET['email'] ?? $defaultEmailRecipient;
$callerIDFromQuery = $_GET['caller_id'] ?? null;
$mailboxFromQuery = $_GET['mailbox'] ?? null;

// Get the raw POST data
$rawData = file_get_contents('php://input');
logMessage("Raw data received (first 500 chars): " . substr($rawData, 0, 500));

// Parse JSON data
$webhookData = json_decode($rawData, true);

if (!$webhookData) {
    logMessage("ERROR: Failed to parse JSON data");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Get job ID and status
$jobId = $webhookData['id'] ?? null;
$status = $webhookData['status'] ?? 'Unknown';

if (!$jobId) {
    logMessage("ERROR: No job ID in webhook data");
    http_response_code(400);
    echo json_encode(['error' => 'No job ID']);
    exit;
}

logMessage("Job ID: $jobId");
logMessage("Status: $status");

// Check if transcription is complete
if ($status !== 'completed') {
    logMessage("Job not completed yet, status: $status");
    http_response_code(200);
    echo json_encode(['message' => 'Status received', 'status' => $status]);
    exit;
}

// Extract metadata from name field
$nameField = $webhookData['name'] ?? '';
$metadata = extractMetadata($nameField);

// Determine caller info (prefer metadata, fallback to query params)
$callerID = 'Unknown';
$mailbox = 'Unknown';
$timestamp = date('Y-m-d H:i:s');

if ($metadata) {
    $callerID = $metadata['caller_id'] ?? $callerID;
    $mailbox = $metadata['mailbox'] ?? $mailbox;
    $timestamp = $metadata['timestamp'] ?? $timestamp;
    $emailRecipient = $metadata['email'] ?? $emailRecipient;
    logMessage("Metadata extracted successfully");
} else {
    // Use query parameters as fallback
    $callerID = $callerIDFromQuery ?? $callerID;
    $mailbox = $mailboxFromQuery ?? $mailbox;
    logMessage("Using query parameters for caller info");
}

logMessage("Caller ID: $callerID");
logMessage("Mailbox: $mailbox");
logMessage("Email Recipient: $emailRecipient");

// Send email
$emailSent = sendEmailTranscription(
    $emailRecipient,
    $callerID,
    $mailbox,
    $timestamp,
    $webhookData,
    $emailFrom,
    $emailFromName
);

if ($emailSent) {
    logMessage("✓ Email sent successfully to: $emailRecipient");
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Email sent successfully',
        'job_id' => $jobId,
        'recipient' => $emailRecipient
    ]);
} else {
    logMessage("✗ Failed to send email");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send email',
        'job_id' => $jobId
    ]);
}

logMessage("=== Webhook Processing Complete ===\n");

?>
