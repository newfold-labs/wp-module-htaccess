# Integration

The module registers with the Newfold Module Loader via bootstrap.php. Other modules (e.g. wp-module-performance) use its API to register .htaccess fragments. The host plugin typically depends on it when using performance or other modules that need .htaccess rules.
