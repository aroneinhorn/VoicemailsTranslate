<?php

/**
 * YiddishLabs Voicemail Transcription Webhook Handler
 * For Render deployment: https://voicemail-translate.onrender.com
 */

// CORS headers - allow browser testing
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

// Build email content - OUTLOOK COMPATIBLE VERSION
function buildEmailContent($callerID, $mailbox, $timestamp, $transcriptionData) {
    $summaryText = $transcriptionData['summary'] ?? 'No transcription available';
    $transcriptionText = $transcriptionData['text'] ?? 'No transcription available';
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
    $textBody .= "Keywords: $keywordsText\n";
    $textBody .= "Summary: $summaryText\n\n";
    $textBody .= "=================================\n";
    $textBody .= "TRANSCRIPTION:\n";
    $textBody .= "=================================\n\n";
    $textBody .= wordwrap($transcriptionText, 70) . "\n\n";
    $textBody .= "=================================\n";
    $textBody .= "Job ID: $jobId\n";
    $textBody .= "=================================\n";

    // HTML version - TABLE-BASED FOR OUTLOOK COMPATIBILITY
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!--[if mso]>
    <style type="text/css">
        body, table, td {font-family: Arial, sans-serif !important;}
    </style>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, sans-serif;">
    <!-- Main wrapper table -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f4f4;">
        <tr>
            <td style="padding: 20px 0;">
                <!-- Content container -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" align="center" style="background-color: #ffffff; margin: 0 auto;">
                    
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #667eea; color: #ffffff; padding: 30px 20px; text-align: center;">
                            <h1 style="margin: 0; font-size: 24px; font-weight: normal;">📞 New Voicemail Transcription</h1>
                        </td>
                    </tr>
                    
                    <!-- Content padding wrapper -->
                    <tr>
                        <td style="padding: 30px 20px;">
                            
                            <!-- Info Box -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f8f9fa; border-left: 4px solid #667eea;">
                                <tr>
                                    <td style="padding: 15px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="padding: 8px 0;">
                                                    <span style="font-weight: 600; color: #555555; display: inline-block; min-width: 100px;">From:</span>
                                                    <span style="color: #333333;"><strong>$callerID</strong></span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0;">
                                                    <span style="font-weight: 600; color: #555555; display: inline-block; min-width: 100px;">Mailbox:</span>
                                                    <span style="color: #333333;">$mailbox</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0;">
                                                    <span style="font-weight: 600; color: #555555; display: inline-block; min-width: 100px;">Received:</span>
                                                    <span style="color: #333333;">$timestamp</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0;">
                                                    <span style="font-weight: 600; color: #555555; display: inline-block; min-width: 100px;">Duration:</span>
                                                    <span style="color: #333333;">$duration seconds</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Keywords Box -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 20px;">
                                <tr>
                                    <td style="background-color: #f0f7ff; padding: 10px; font-size: 14px; color: #333333;">
                                        <strong>🔑 Keywords:</strong> $keywordsText
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Summary Box -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 20px;">
                                <tr>
                                    <td style="padding: 10px 0; color: #333333;">
                                        <strong>📋 Summary:</strong> $summaryText
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Transcription Box -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin-top: 20px; background-color: #ffffff; border: 2px solid #e9ecef;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <h2 style="margin: 0 0 15px 0; color: #667eea; font-size: 18px; font-weight: 600;">📝 Transcription</h2>
                                        <div style="font-size: 16px; line-height: 1.8; white-space: pre-wrap; direction: rtl; text-align: right; color: #333333;">$transcriptionText</div>
                                    </td>
                                </tr>
                            </table>
                            
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666666;">
                            Job ID: $jobId<br>
                            Powered by YiddishLabs
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
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

    // Mask email function
    function maskEmail($email) {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '***';
        }
        list($username, $domain) = explode('@', $email);
        $maskedUsername = substr($username, 0, 2) . str_repeat('*', max(0, strlen($username) - 2));
        return $maskedUsername . '@' . $domain;
    }

    $response = [
        'status' => 'ok',
        'service' => 'YiddishLabs Voicemail Webhook',
        'version' => '1.0',
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s'),
        'sendgrid_configured' => !empty($sendgridApiKey)
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

        // Parse JSON
        $webhookData = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("ERROR: JSON parse error - " . json_last_error_msg());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }

        // Handle nested structure (YiddishLabs format)
        $event = $webhookData['event'] ?? null;
        $jobData = $webhookData['data'] ?? $webhookData; // Fallback to root if no 'data' wrapper

        // Extract job details
        $jobId = $jobData['id'] ?? null;
        $status = $jobData['status'] ?? 'Unknown';

        if (!$jobId) {
            logMessage("ERROR: No job ID in webhook data");
            http_response_code(400);
            echo json_encode(['error' =>  'No job ID']);
            exit;
        }

        logMessage("Event: $event | Job ID: $jobId | Status: $status");

        // Handle failed transcriptions
        if ($event === 'transcription.failed' && $status === 'failed') {
            logMessage("Transcription actually failed for job $jobId");
            http_response_code(200);
            echo json_encode([
                'message' => 'Transcription failed acknowledged',
                'job_id' => $jobId,
                'event' => $event
            ]);
            exit;
        }

        // Only process completed transcriptions
        if ($status !== 'completed' && $event !== 'transcription.completed') {
            logMessage("Job not completed yet (status: $status, event: $event). Acknowledging.");
            http_response_code(200);
            echo json_encode([
                'message' => 'Status received',
                'status' => $status,
                'event' => $event,
                'job_id' => $jobId
            ]);
            exit;
        }

        // Extract caller information
        $nameField = $jobData['name'] ?? '';
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

        // Debug logging
        logMessage("DEBUG: jobData has summary field: " . (isset($jobData['summary']) ? 'YES' : 'NO'));
        if (isset($jobData['summary'])) {
            logMessage("DEBUG: Summary preview: " . substr($jobData['summary'], 0, 50) . "...");
        }

        // Build email content
         $emailContent = buildEmailContent($callerID, $mailbox, $timestamp, $jobData);
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
?>
