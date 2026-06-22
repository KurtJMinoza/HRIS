@echo off
setlocal
set "MYSQLBINLOG=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqlbinlog.exe"
set "MYSQL=C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe"
set "PHP=C:\xampp\php\php.exe"
set "LOGDIR=C:\laragon\data\mysql-8"

"%MYSQL%" -h 127.0.0.1 -P 3306 -u root --init-command="SET SESSION sql_log_bin=0" -e "DROP DATABASE IF EXISTS hris"
if errorlevel 1 exit /b %errorlevel%

"%MYSQLBINLOG%" ^
  "%LOGDIR%\binlog.000011" "%LOGDIR%\binlog.000012" "%LOGDIR%\binlog.000013" ^
  "%LOGDIR%\binlog.000014" "%LOGDIR%\binlog.000015" "%LOGDIR%\binlog.000016" ^
  "%LOGDIR%\binlog.000017" "%LOGDIR%\binlog.000018" "%LOGDIR%\binlog.000019" ^
  "%LOGDIR%\binlog.000020" "%LOGDIR%\binlog.000021" "%LOGDIR%\binlog.000022" ^
  "%LOGDIR%\binlog.000023" "%LOGDIR%\binlog.000024" "%LOGDIR%\binlog.000025" ^
  "%LOGDIR%\binlog.000026" "%LOGDIR%\binlog.000027" "%LOGDIR%\binlog.000028" ^
  "%LOGDIR%\binlog.000029" "%LOGDIR%\binlog.000030" "%LOGDIR%\binlog.000031" ^
  "%LOGDIR%\binlog.000032" "%LOGDIR%\binlog.000033" ^
  | "%PHP%" "%~dp0binlog_fk_filter.php" ^
  | "%MYSQL%" -h 127.0.0.1 -P 3306 -u root --binary-mode --init-command="SET SESSION sql_log_bin=0"
if errorlevel 1 exit /b %errorlevel%

"%MYSQLBINLOG%" --stop-position=956198023 "%LOGDIR%\binlog.000034" ^
  | "%PHP%" "%~dp0binlog_fk_filter.php" ^
  | "%MYSQL%" -h 127.0.0.1 -P 3306 -u root --binary-mode --init-command="SET SESSION sql_log_bin=0"
exit /b %errorlevel%
