<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Personal Nutrition Planner</title>
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
        .welcome-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .content {
            padding: 40px 30px;
        }
        .welcome-text {
            font-size: 18px;
            margin-bottom: 20px;
            color: #28a745;
            font-weight: 600;
        }
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin: 30px 0;
        }
        .feature-card {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #28a745;
        }
        .feature-card .icon {
            font-size: 32px;
            margin-bottom: 10px;
            display: block;
        }
        .feature-card h3 {
            margin: 0 0 10px 0;
            font-size: 16px;
            color: #28a745;
        }
        .feature-card p {
            margin: 0;
            font-size: 14px;
            color: #666;
        }
        .cta-section {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
            border-radius: 8px;
        }
        .cta-button {
            display: inline-block;
            background-color: white;
            color: #28a745;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            font-size: 16px;
            margin-top: 15px;
            transition: transform 0.2s ease;
        }
        .cta-button:hover {
            transform: translateY(-2px);
            text-decoration: none;
            color: #28a745;
        }
        .tips-section {
            background-color: #e8f5e8;
            padding: 25px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .tips-section h3 {
            color: #28a745;
            margin-top: 0;
        }
        .tips-section ul {
            padding-left: 20px;
        }
        .tips-section li {
            margin-bottom: 8px;
            color: #555;
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
            .feature-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            .cta-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="welcome-icon">🎉</div>
            <h1>Welcome to Personal Nutrition Planner</h1>
        </div>
        
        <div class="content">
            <p class="welcome-text">
                Congratulations <span class="highlight">{{ $user->first_name }}</span>! Your account is now verified and ready to use.
            </p>
            
            <p>You've successfully joined thousands of users who are transforming their health through smart nutrition tracking and personalized insights.</p>
            
            <div class="feature-grid">
                <div class="feature-card">
                    <span class="icon">📊</span>
                    <h3>Smart Tracking</h3>
                    <p>Log your meals and track nutrients with our comprehensive food database</p>
                </div>
                <div class="feature-card">
                    <span class="icon">🎯</span>
                    <h3>Goal Setting</h3>
                    <p>Set personalized nutrition goals based on your lifestyle and objectives</p>
                </div>
                <div class="feature-card">
                    <span class="icon">📈</span>
                    <h3>Progress Analytics</h3>
                    <p>Visualize your nutrition patterns with detailed charts and reports</p>
                </div>
                <div class="feature-card">
                    <span class="icon">💡</span>
                    <h3>Smart Insights</h3>
                    <p>Get personalized recommendations to optimize your nutrition</p>
                </div>
            </div>
            
            <div class="cta-section">
                <h2 style="margin-top: 0;">Ready to Start Your Journey?</h2>
                <p>Your personalized nutrition dashboard is waiting for you!</p>
                <a href="#" class="cta-button">Start Tracking Now</a>
            </div>
            
            <div class="tips-section">
                <h3>🚀 Quick Start Tips</h3>
                <ul>
                    <li><strong>Complete your profile:</strong> Add your health metrics for personalized calculations</li>
                    <li><strong>Set your goals:</strong> Define what you want to achieve (weight loss, muscle gain, etc.)</li>
                    <li><strong>Log your first meal:</strong> Start tracking to see real-time nutrition data</li>
                    <li><strong>Review your dashboard:</strong> Check your daily progress and recommendations</li>
                </ul>
            </div>
            
            <p>If you have any questions or need help getting started, our support team is here to help!</p>
            
            <p>Happy tracking!</p>
        </div>
        
        <div class="footer">
            <p><strong>Personal Nutrition Planner Team</strong></p>
            <p>This is an automated message, please do not reply to this email.</p>
            <p>Need help? Contact our support team anytime.</p>
        </div>
    </div>
</body>
</html>