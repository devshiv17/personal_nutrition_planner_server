<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .content {
            padding: 40px 30px;
        }
        .welcome-text {
            font-size: 18px;
            margin-bottom: 20px;
            color: #555;
        }
        .verify-button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        .verify-button:hover {
            transform: translateY(-2px);
            text-decoration: none;
            color: white;
        }
        .alternative-link {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #667eea;
        }
        .alternative-link p {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #666;
        }
        .alternative-link a {
            word-break: break-all;
            color: #667eea;
            text-decoration: none;
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
            color: #667eea;
            font-weight: 600;
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
            <h1>🥗 Personal Nutrition Planner</h1>
        </div>
        
        <div class="content">
            <p class="welcome-text">
                Hello <span class="highlight">{{ $user->first_name }}</span>,
            </p>
            
            <p>Welcome to Personal Nutrition Planner! We're excited to help you on your nutrition journey.</p>
            
            <p>To get started, please verify your email address by clicking the button below:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{{ $url }}" class="verify-button">Verify Email Address</a>
            </div>
            
            <p>This verification link will expire in <strong>60 minutes</strong> for your security.</p>
            
            <div class="alternative-link">
                <p><strong>Having trouble with the button?</strong></p>
                <p>Copy and paste this URL into your browser:</p>
                <a href="{{ $url }}">{{ $url }}</a>
            </div>
            
            <p>Once your email is verified, you'll be able to:</p>
            <ul>
                <li>📊 Track your daily nutrition intake</li>
                <li>🎯 Set and monitor your health goals</li>
                <li>📈 View detailed nutrition analytics</li>
                <li>🔔 Receive personalized recommendations</li>
            </ul>
            
            <p>If you didn't create an account with us, please ignore this email.</p>
        </div>
        
        <div class="footer">
            <p><strong>Personal Nutrition Planner Team</strong></p>
            <p>This is an automated message, please do not reply to this email.</p>
            <p>Need help? Contact our support team.</p>
        </div>
    </div>
</body>
</html>