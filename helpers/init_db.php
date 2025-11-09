<?php

if (! file_exists("/app/databases/telescope.sqlite")) {
    if (! file_exists("/app/databases")) mkdir("/app/databases");
    touch("/app/databases/telescope.sqlite");
}