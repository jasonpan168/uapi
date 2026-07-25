<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    public static function send($to, $subject, $body) {
        
        // Try to load composer autoload from project root if not loaded
        if (!class_exists(PHPMailer::class) && file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        }

        // Manual Include for PHPMailer if Composer not used
        if (!class_exists(PHPMailer::class)) {
            // This file lives in <project root>/src, so project root is two levels up
            $root = __DIR__ . '/../..';
            
            // Check for PHPMailer folder structure in root
            // Option 1: PHPMailer/src/... (Standard Git clone)
            if (file_exists("$root/PHPMailer/src/PHPMailer.php")) {
                require_once "$root/PHPMailer/src/Exception.php";
                require_once "$root/PHPMailer/src/PHPMailer.php";
                require_once "$root/PHPMailer/src/SMTP.php";
            }
            // Option 2: phpmailer/src/... (Lowercase folder)
            elseif (file_exists("$root/phpmailer/src/PHPMailer.php")) {
                require_once "$root/phpmailer/src/Exception.php";
                require_once "$root/phpmailer/src/PHPMailer.php";
                require_once "$root/phpmailer/src/SMTP.php";
            }
        }

        // Check if PHPMailer is available via Composer OR Manual Include
        if (class_exists(PHPMailer::class)) {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = 'base64';

            try {
                //Server settings
                $mail->isSMTP();
                $mail->Host       = Helper::getSetting('mail_host', Env::get('MAIL_HOST'));
                $mail->SMTPAuth   = true;
                $mail->Username   = Helper::getSetting('mail_user', Env::get('MAIL_USER'));
                $mail->Password   = Helper::getSetting('mail_pass', Env::get('MAIL_PASS'));
                
                // Auto-detect encryption based on port
                $port = (int)Helper::getSetting('mail_port', Env::get('MAIL_PORT', 587));
                $mail->Port = $port;
                
                if ($port === 465) {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Implicit SSL for 465
                } else {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Explicit TLS for 587/25
                }
                
                $mail->Timeout    = 10; // Timeout in seconds

                //Recipients
                $mail->setFrom(
                    Helper::getSetting('mail_from_address', Env::get('MAIL_FROM_ADDRESS')), 
                    Helper::getSetting('mail_from_name', Env::get('MAIL_FROM_NAME'))
                );
                $mail->addAddress($to);

                //Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;

                $mail->send();
                return true;
            } catch (Exception $e) {
                // Log and Re-throw to show error in UI
                error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
                // If called from admin test context, rethrow to display error
                if (strpos($_SERVER['REQUEST_URI'] ?? '', 'admin/settings.php') !== false) {
                    throw new \Exception($mail->ErrorInfo);
                }
                return false;
            } catch (\Exception $e) {
                error_log("Message could not be sent. Error: {$e->getMessage()}");
                if (strpos($_SERVER['REQUEST_URI'] ?? '', 'admin/settings.php') !== false) {
                    throw $e;
                }
                return false;
            }
        } else {
            // Fallback to native PHP mail() if PHPMailer is not installed
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: ' . Env::get('MAIL_FROM_NAME') . ' <' . Env::get('MAIL_FROM_ADDRESS') . '>' . "\r\n";
            
            return mail($to, $subject, $body, $headers);
        }
    }
}
