<?php

namespace App\Enums;

enum PostFileKind: string
{
    case Image = 'image';
    case Attachment = 'attachment';
}
