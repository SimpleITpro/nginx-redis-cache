# nginx-redis-cache
Nginx Cache stored in Redis management. To use only with NGINX HTTP Redis module.

/** Nginx Cache in Redis wp-config.php Settings */

<--Required, update according Redis server configuration:-->

define('NGINX_REDIS_HOST', '127.0.0.1'); // Redis server host

define('NGINX_REDIS_PORT', 6379); // Redis server port

define('NGINX_REDIS_PREFIX', 'nginx-cache'); // Redis key

define('NGINX_REDIS_DB', 0);               // Redis DB index

define('NGINX_REDIS_AUTH', null);          // 'password' or null

define('NGINX_REDIS_TIMEOUT', 1.5);        // seconds

