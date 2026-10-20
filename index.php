<?php
// Serves the app at the site's root address on Azure App Service (its web server looks for index.php first).
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
