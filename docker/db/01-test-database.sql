-- The WordPress test suite empties the database it is given: it gets its own.
CREATE DATABASE IF NOT EXISTS wordpress_test;
GRANT ALL PRIVILEGES ON wordpress_test.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
