<?php

namespace App\Enums;

enum ChallengePurpose: string
{
    case LOGIN = 'login';
    case PASSWORD_RESET = 'password_reset';
}
