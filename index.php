<?php

/**
 * YiddishLabs Voicemail Transcription Webhook Handler
 * For Render deployment: https://voicemail-translate.onrender.com
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error handling - log to Render, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Configuration from environment variables
$sendgridApiKey = getenv('SENDGRID_API_KEY');
$emailFrom = getenv('EMAIL_FROM') ?: 'voicemail@yourdomain.com';
$emailFromName = 'FreePBX Voicemail';
$defaultEmailRecipient = getenv('DEFAULT_EMAIL_RECIPIENT') ?: 'your-email@example.com';

// Logging function
function logMessage($message) {
    error_log(date('[Y-m-d H:i:s]') . " " . $message);
}

// Extract metadata from name field
function extractMetadata($nameField) {
    if (preg_match('/METADATA:(.+)$/', $nameField, $matches)) {
        $metadataJson = base64_decode($matches[1]);
        return json_decode($metadataJson, true);
    }
    return null;
}

// SendGrid email sender
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
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
        logMessage("SendGrid: Email sent successfully");
        return true;
    } else {
        logMessage("SendGrid Error Response: $response");
        return false;
    }
}

// Build email content
// Build email content
function buildEmailContent($callerID, $mailbox, $timestamp, $transcriptionData) {
    // YiddishLabs uses  'summary' for transcription text
    $transcriptionText = $transcriptionData['summary'] ?? 'No transcription available';
    $duration = $transcriptionData['duration_seconds'] ?? 'Unknown';
    $jobId = $transcriptionData['id'] ?? 'Unknown';
    $keywords = $transcriptionData['keywords'] ?? [];
    $keywordsText = !empty($keywords) ? implode(', ', $keywords) : 'None';

    // Plain text version
    $textBody = "=================================\n";
    $textBody .= "VOICEMAIL TRANSCRIPTION\n";
    $textBody .= "=================================\n\n";
    $textBody .= "From: $callerID\n";
    $textBody .= "Mailbox: $mailbox\n";
    $textBody .= "Received: $timestamp\n";
    $textBody .= "Duration: $duration seconds\n";
    $textBody .= "Keywords: $keywordsText\n\n";
    $textBody .= "=================================\n";
    $textBody .= "TRANSCRIPTION:\n";
    $textBody .= "=================================\n\n";
    $textBody .= wordwrap($transcriptionText, 70) . "\n\n";
    $textBody .= "=================================\n";
    $textBody .= "Job ID: $jobId\n";
    $textBody .= "=================================\n";

    // HTML version
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px 20px; }
        .info-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .info-row { padding: 8px 0; }
        .label { font-weight: 600; color: #555; display: inline-block; min-width: 100px; }
        .value { color: #333; }
        .transcription { background: #fff; border: 2px solid #e9ecef; padding: 20px; margin: 20px 0; border-radius: 8px; }
        .transcription h2 { margin: 0 0 15px 0; color: #667eea; font-size: 18px; }
        .trans-text { font-size: 16px; line-height: 1.8; white-space: pre-wrap; direction: rtl; text-align: right; }
        .keywords { background: #f0f7ff; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 14px; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📞 New Voicemail Transcription</h1>
        </div>
        <div class="content">
            <div class="info-box">
                <div class="info-row"><span class="label">From:</span> <span class="value"><strong>$callerID</strong></span></div>
                <div class="info-row"><span class="label">Mailbox:</span> <span class="value">$mailbox</span></div>
                <div class="info-row"><span class="label">Received:</span> <span class="value">$timestamp</span></div>
                <div class="info-row"><span class="label">Duration:</span> <span class="value">$duration seconds</span></div>
            </div>
            <div class="keywords">
                <strong>🔑 Keywords:</strong> $keywordsText
            </div>
            <div class="transcription">
                <h2>📝 Transcription</h2>
                <div class="trans-text">$transcriptionText</div>
            </div>
        </div>
        <div class="footer">
            Job ID: $jobId<br>
            Powered by YiddishLabs
        </div>
    </div>
</body>
</html>
HTML;

    return ['text' => $textBody, 'html' => $htmlBody];
}

// ==============================================
// MAIN EXECUTION
// ==============================================

// Set JSON header
header('Content-Type: application/json');

// Handle GET requests (for testing)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    logMessage("GET request received - Health check");
    
    $response = [
        'status' => 'ok',
        'service' => 'YiddishLabs Voicemail Webhook',
        'version' => '1.0',
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s'),
        'sendgrid_configured' => !empty($sendgridApiKey),
        'email_from' => $emailFrom,
        'default_recipient' => $defaultEmailRecipient
    ];
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Handle POST requests (webhook from YiddishLabs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logMessage("=== POST Webhook Request Received ===");
    
    try {
        // Get query parameters
        $emailRecipient = $_GET['email'] ?? $defaultEmailRecipient;
        $callerIDFromQuery = $_GET['caller_id'] ?? null;
        $mailboxFromQuery = $_GET['mailbox'] ?? null;
        
        logMessage("Email recipient: $emailRecipient");
        
        // Get POST data
        $rawData = file_get_contents('php://input');
        
        if (empty($rawData)) {
            logMessage("ERROR: Empty request body");
            http_response_code(400);
            echo json_encode(['error' => 'Empty request body']);
            exit;
        }
        
        logMessage("Raw data received (length: " . strlen($rawData) . ")");
        logMessage("RAW PAYLOAD: " . $rawData); 

        // Parse JSON
        $webhookData = json_decode($rawData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("ERROR: JSON parse error - " . json_last_error_msg());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }
        
        // Extract job details
        $jobId = $webhookData['id'] ?? null;
        $status = $webhookData['status'] ?? 'Unknown';
        
        if (!$jobId) {
            logMessage("ERROR: No job ID in webhook data");
            http_response_code(400);
            echo json_encode(['error' => 'No job ID']);
            exit;
        }
        
        logMessage("Job ID: $jobId | Status: $status");
        
        // Only process completed transcriptions
        if ($status !== 'completed') {
            logMessage("Job not completed yet (status: $status). Acknowledging.");
            http_response_code(200);
            echo json_encode([
                'message' => 'Status received',
                'status' => $status,
                'job_id' => $jobId
            ]);
            exit;
        }
        
        // Extract caller information
        $nameField = $webhookData['name'] ?? '';
        $metadata = extractMetadata($nameField);
        
        $callerID = 'Unknown';
        $mailbox = 'Unknown';
        $timestamp = date('Y-m-d H:i:s');
        
        if ($metadata) {
            $callerID = $metadata['caller_id'] ?? $callerID;
            $mailbox = $metadata['mailbox'] ?? $mailbox;
            $timestamp = $metadata['timestamp'] ?? $timestamp;
            $emailRecipient = $metadata['email'] ?? $emailRecipient;
            logMessage("Metadata extracted: Caller=$callerID, Mailbox=$mailbox");
        } else {
            // Fallback to query parameters
            $callerID = $callerIDFromQuery ?? $callerID;
            $mailbox = $mailboxFromQuery ?? $mailbox;
            logMessage("Using query parameters: Caller=$callerID, Mailbox=$mailbox");
        }
        
        // Build email content
        $emailContent = buildEmailContent($callerID, $mailbox, $timestamp, $webhookData);
        $subject = "Voicemail from $callerID";
        
        logMessage("Sending email to: $emailRecipient");
        
        // Send email via SendGrid
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
            logMessage("✓ Email sent successfully to $emailRecipient");
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Email sent successfully',
                'job_id' => $jobId,
                'recipient' => $emailRecipient,
                'caller_id' => $callerID,
                'mailbox' => $mailbox
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
        
    } catch (Exception $e) {
        logMessage("EXCEPTION: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => $e->getMessage()
        ]);
    }
    
    logMessage("=== Webhook Processing Complete ===\n");
    exit;
}

// Handle other methods
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
