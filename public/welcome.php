<?php
include '../includes/config.php';
// Check if user is logged in - but don't redirect if not, as this is a public welcome page
// The layout.php will handle authentication
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transition | Integration | Training</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #011f88;
            --accent-color: #00d2ff;
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary-color) 0%, #0056b3 100%);
            color: white;
            padding: 30px 0;
            margin-bottom: 40px;
        }
        .feature-card {
            border: none;
            border-radius: 15px;
            transition: transform 0.3s;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            height: 100%;
        }
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(1, 31, 136, 0.1);
        }
        .icon-box {
            width: 70px;
            height: 70px;
            line-height: 70px;
            background: rgba(1, 31, 136, 0.1);
            color: var(--primary-color);
            border-radius: 50%;
            font-size: 28px;
            margin-bottom: 20px;
            display: inline-block;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
        }
        .btn-primary:hover {
            background-color: #003d9e;
        }
        .btn-outline-light {
            padding: 12px 30px;
            border-radius: 8px;
        }
        .stat-box {
            padding: 30px;
            border-radius: 15px;
            background: white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        .color-primary {
            color: var(--primary-color);
        }
        .welcome-message {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin: 40px 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>
    <div class="hero-section text-center">
        <div class="container">
            <h1 class="display-3 fw-bold mb-3">Transition | Integration | Training</h1>
        </div>
    </div>

    <!-- Welcome Message -->
    <div class="container">
        <div class="welcome-message text-center">
            <h2 class="fw-bold color-primary mb-3">Welcome to Transition | Integration | Training tracker</h2>
            <?php if(isset($_SESSION['full_name'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i> Welcome back, <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>!
                </div>
            <?php endif; ?>
        </div>
    </div>

    <section class="bg-white py-8">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="stat-box text-center">
                        <h2 class="fw-bold color-primary">Paperless Tracking</h2>
                        <p class="text-muted mb-0">100% Paperless Data Collection</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center">
                        <h2 class="fw-bold color-primary">Real-time</h2>
                        <p class="text-muted mb-0">Reporting & Analytics</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center">
                        <h2 class="fw-bold color-primary">Secure</h2>
                        <p class="text-muted mb-0">Data Encryption</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box text-center">
                        <h2 class="fw-bold color-primary">AI Enabled</h2>
                        <p class="text-muted mb-0">AI generated workplans</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>