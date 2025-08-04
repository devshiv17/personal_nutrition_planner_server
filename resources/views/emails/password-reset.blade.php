<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .security-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .content {
            padding: 40px 30px;
        }
        .alert-text {
            font-size: 18px;
            margin-bottom: 20px;
            color: #dc3545;
            font-weight: 600;
        }
        .reset-button {
            display: inline-block;
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        .reset-button:hover {
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }
        .alternative-link {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #dc3545;
        }
        .alternative-link p {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #666;
        }
        .alternative-link a {
            word-break: break-all;
            color: #dc3545;
            text-decoration: none;
        }
        .security-notice {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
        }
        .security-notice h3 {
            color: #856404;
            margin-top: 0;
            font-size: 16px;
        }
        .security-notice ul {
            color: #856404;
            padding-left: 20px;
            margin: 10px 0;
        }
        .security-notice li {
            margin-bottom: 5px;
        }
        .token-info {
            background-color: #e8f4fd;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 14px;
            color: #0c5460;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #666;
            border-top: 1px solid #eee;
        }
        .footer p {
            margin: 5px 0;
        }
        .highlight {
            color: #dc3545;
            font-weight: 600;
        }
        .muted {
            color: #6c757d;
            font-size: 12px;
        }
        @media (max-width: 600px) {
            .container {
                margin: 10px;
                border-radius: 0;
            }
            .content {
                padding: 20px;
            }
            .header {
                padding: 20px;
            }
            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="security-icon">🔐</div>
            <h1>Password Reset Request</h1>
        </div>
        
        <div class="content">
            <p class="alert-text">
                Hello <span class="highlight">{{ $user->first_name }}</span>,
            </p>
            
            <p>We received a request to reset the password for your Personal Nutrition Planner account associated with <strong>{{ $user->email }}</strong>.</p>
            
            <p>If you made this request, click the button below to reset your password:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $resetUrl }}" class="reset-button">Reset My Password</a>
            </div>
            
            <div class="token-info">
                <strong>⏰ Important:</strong> This reset link will expire in <strong>60 minutes</strong> for your security.
            </div>
            
            <div class="alternative-link">
                <p><strong>Having trouble with the button?</strong></p>
                <p>Copy and paste this URL into your browser:</p>
                <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
            </div>
            
            <div class="security-notice">
                <h3>🛡️ Security Information</h3>
                <ul>
                    <li><strong>Request Details:</strong></li>
                    <li>IP Address: {{ $resetToken->ip_address }}</li>
                    <li>Time: {{ $resetToken->created_at->format('M j, Y \a\t g:i A T') }}</li>
                    <li>Expires: {{ $resetToken->expires_at->format('M j, Y \a\t g:i A T') }}</li>
                </ul>
                <p><strong>⚠️ If you didn't request this password reset:</strong></p>
                <ul>
                    <li>Your account is still secure - no changes have been made</li>
                    <li>You can safely ignore this email</li>
                    <li>Consider enabling two-factor authentication</li>
                    <li>Contact our support team if you're concerned</li>
                </ul>
            </div>
            
            <p>After clicking the reset link, you'll be able to create a new secure password for your account.</p>
            
            <p class="muted">
                <strong>Note:</strong> For security reasons, this link can only be used once and will expire automatically.
            </p>
        </div>
        
        <div class="footer">
            <p><strong>Personal Nutrition Planner Security Team</strong></p>
            <p>This is an automated security message, please do not reply to this email.</p>
            <p>If you need assistance, please contact our support team.</p>
            <p class="muted">Token ID: {{ substr($resetToken->token, 0, 8) }}...</p>
        </div>
    </div>
</body>
</html>