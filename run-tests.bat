@echo off
cd /d C:\laragon\www\kmoney-app
php artisan test 2>&1 > C:\laragon\www\kmoney-app\test-output.txt
echo DONE >> C:\laragon\www\kmoney-app\test-output.txt
