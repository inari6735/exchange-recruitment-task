-- Runs only when the MariaDB volume is created for the first time.
CREATE DATABASE IF NOT EXISTS `app_test`;
GRANT ALL PRIVILEGES ON `app_test`.* TO 'app'@'%';
