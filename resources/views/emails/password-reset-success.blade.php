<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful</title>
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
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .success-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .content {
            padding: 40px 30px;
        }
        .success-text {
            font-size: 18px;
            margin-bottom: 20px;
            color: #28a745;
            font-weight: 600;
        }
        .info-card {
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
        }
        .info-card h3 {
            color: #0c5460;
            margin-top: 0;
            font-size: 16px;
        }
        .info-card ul {
            color: #0c5460;
            padding-left: 20px;
            margin: 10px 0;
        }
        .info-card li {
            margin-bottom: 5px;
        }
        .security-tips {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
        }
        .security-tips h3 {
            color: #856404;
            margin-top: 0;
            font-size: 16px;
        }
        .security-tips ul {
            color: #856404;
            padding-left: 20px;
            margin: 10px 0;
        }
        .security-tips li {
            margin-bottom: 8px;
        }
        .login-button {
            display: inline-block;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        .login-button:hover {
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
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
            color: #28a745;
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
            <div class="success-icon">✅</div>
            <h1>Password Reset Successful</h1>
        </div>
        
        <div class="content">
            <p class="success-text">
                Hello <span class="highlight">{{ $user->first_name }}</span>,
            </p>
            
            <p>Your password has been successfully reset for your Personal Nutrition Planner account (<strong>{{ $user->email }}</strong>).</p>
            
            <p>You can now log in with your new password.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="#" class="login-button">Go to Login</a>
            </div>
            
            <div class="info-card">
                <h3>📋 Reset Details</h3>
                <ul>
                    <li><strong>Date & Time:</strong> {{ $resetTime->format('M j, Y \a\t g:i A T') }}</li>
                    <li><strong>IP Address:</strong> {{ $ipAddress }}</li>
                    <li><strong>Account:</strong> {{ $user->email }}</li>
                </ul>
            </div>
            
            <div class="security-tips">
                <h3>🛡️ Security Recommendations</h3>
                <ul>
                    <li><strong>Keep your password secure:</strong> Don't share it with anyone</li>
                    <li><strong>Use a unique password:</strong> Don't reuse passwords from other accounts</li>
                    <li><strong>Enable notifications:</strong> Stay informed about account activity</li>
                    <li><strong>Regular updates:</strong> Consider changing your password periodically</li>
                    <li><strong>Secure devices:</strong> Always log out from shared computers</li>
                </ul>
            </div>
            
            <p><strong>⚠️ Didn't reset your password?</strong></p>
            <p>If you didn't request this password reset, please contact our support team immediately. Your account security is important to us.</p>
            
            <p>All your existing login sessions have been terminated for security purposes. You'll need to log in again on all devices.</p>
        </div>
        
        <div class="footer">
            <p><strong>Personal Nutrition Planner Security Team</strong></p>
            <p>This is an automated security notification, please do not reply to this email.</p>
            <p>Need help? Contact our support team anytime.</p>
        </div>
    </div>
</body>
</html>