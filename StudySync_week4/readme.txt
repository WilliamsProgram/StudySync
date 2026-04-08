StudySync is a PHP and MySQL web application that helps NCU students manage courses, assignments, study schedules, resources, study groups, notifications, and GPA tracking in one place.

HOW TO RUN THE PROJECT
1. Install XAMPP.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Copy the project folder into C:\xampp\htdocs\
4. Open phpMyAdmin at http://localhost/phpmyadmin
5. Create a database named studysync_db if it does not already exist.
6. Import the included studysync_db.sql file into the studysync_db database.
7. Make sure the project config.php file points to your local MySQL settings, such as host localhost, username root, password blank if you use the default XAMPP setup, and database name studysync_db.
8. Confirm that the uploaded logo and uploads/resources folders remain inside the project directory.
9. Open the project in your browser using http://localhost/PROJECT_FOLDER_NAME/
10. If the homepage does not open automatically, go to http://localhost/PROJECT_FOLDER_NAME/login.php
11. Register a new account or use an existing one from your imported database.
12. After login, you should be taken to the dashboard.

TROUBLESHOOTING
- If the database does not connect, check that MySQL is running and that config.php has the correct database name, username, and password.
- If pages load without data, confirm that studysync_db.sql was imported successfully.
- If uploads do not work, make sure the uploads/resources folder exists and is writable.
- If you get PHP extension errors, run the project in XAMPP with MySQLi enabled.
