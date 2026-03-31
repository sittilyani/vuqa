<?php
  include ("../includes/config.php");
  include ("../includes/session_check.php");
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css" type="text/css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {

            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            padding: 20px;
        }

        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            padding: 30px;
            max-width: 1600px;
            margin: 0 auto;
        }

        .grid-item {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: slideIn 0.5s ease-out;
        }

        .grid-item:hover {
            transform: translateY(-10px);
            box-shadow: 0 30px 50px rgba(0, 0, 0, 0.3);
            background: rgba(255, 255, 255, 1);
        }

        .grid-item h4 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 4px solid;
            position: relative;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Different colors for each section header */
        .grid-item:nth-child(1) h4 {
            border-bottom-color: #e74c3c;
            color: #e74c3c;
        }
        .grid-item:nth-child(2) h4 {
            border-bottom-color: #27ae60;
            color: #27ae60;
        }
        .grid-item:nth-child(3) h4 {
            border-bottom-color: #f39c12;
            color: #f39c12;
        }
        .grid-item:nth-child(4) h4 {
            border-bottom-color: #3498db;
            color: #3498db;
        }
        .grid-item:nth-child(5) h4 {
            border-bottom-color: #9b59b6;
            color: #9b59b6;
        }

        .grid-item ul {
            list-style: none;
            margin: 8px 0;
            padding: 0;
        }

        .grid-item a {
            display: block;
            font-size: 1rem;
            font-weight: 500;
            color: #34495e;
            text-decoration: none;
            padding: 10px 15px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
            margin: 5px 0;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .grid-item a:hover {
            background: white;
            border-left-color: currentColor;
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            color: #2c3e50;
            font-weight: 600;
        }

        /* Color-coded link hover effects based on section */
        .grid-item:nth-child(1) a:hover {
            border-left-color: #e74c3c;
            background: linear-gradient(to right, #fff, #fff5f5);
        }
        .grid-item:nth-child(2) a:hover {
            border-left-color: #27ae60;
            background: linear-gradient(to right, #fff, #f0fff4);
        }
        .grid-item:nth-child(3) a:hover {
            border-left-color: #f39c12;
            background: linear-gradient(to right, #fff, #fffaf0);
        }
        .grid-item:nth-child(4) a:hover {
            border-left-color: #3498db;
            background: linear-gradient(to right, #fff, #f0f9ff);
        }
        .grid-item:nth-child(5) a:hover {
            border-left-color: #9b59b6;
            background: linear-gradient(to right, #fff, #faf5ff);
        }

        /* Animation keyframes */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Stagger animation for grid items */
        .grid-item:nth-child(1) { animation-delay: 0.1s; }
        .grid-item:nth-child(2) { animation-delay: 0.2s; }
        .grid-item:nth-child(3) { animation-delay: 0.3s; }
        .grid-item:nth-child(4) { animation-delay: 0.4s; }
        .grid-item:nth-child(5) { animation-delay: 0.5s; }

        /* Responsive design */
        @media (max-width: 1200px) {
            .grid-container {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 900px) {
            .grid-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                padding: 20px;
            }

            .grid-item h4 {
                font-size: 1.2rem;
            }
        }

        @media (max-width: 600px) {
            .grid-container {
                grid-template-columns: 1fr;
                padding: 15px;
            }

            body {
                padding: 10px;
            }

            .grid-item {
                padding: 20px 15px;
            }

            .grid-item a {
                padding: 8px 12px;
                font-size: 0.95rem;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2, #667eea);
        }

        /* Add a subtle pattern overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image:
                radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* Ensure content stays above the overlay */
        .grid-container {
            position: relative;
            z-index: 1;
        }
    </style>
</head>
<body>
     <div class="grid-container">
        <div class="grid-item">
             <h4>Database and Users</h4>
             <ul><a href="../backup/index.php">BackUp Database</a></ul>
             <ul><a href="../views/userslist.php">Users' List'</a></ul>
             <ul><a href="../public/user_registration.php">Register User</a></ul>
        </div>
         <div class="grid-item">
             <h4>Courses/Trainings</h4>
             <ul><a href="add_qualification.php">Add Academic Qualification</a></ul>
             <ul><a href="add_professional_body.php">Add Professional Body</a></ul>
             <ul><a href="add_training.php">Add Trainings</a></ul>
             <ul><a href="add_training_type.php">Add Training Type</a></ul>
             <ul><a href="add_training_location.php">Add Training Location</a></ul>
         </div>
         <div class="grid-item">
             <h4>General</h4>
             <ul><a href="add_county.php">Add Counties</a></ul>
             <ul><a href="add_sub_county.php">Add Sub Counties</a> </ul>
             <ul><a href="add_facility.php">Add Facility</a></ul>
             <ul><a href="add_department.php">Add Departments</a></ul>
             <ul><a href="add_cadre.php">Add Cadres</a></ul>
         </div>
      </div>

</body>
</html>