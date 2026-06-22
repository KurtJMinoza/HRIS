<?php

while (($line = fgets(STDIN)) !== false) {
    echo str_replace('foreign_key_checks=1', 'foreign_key_checks=0', $line);
}
