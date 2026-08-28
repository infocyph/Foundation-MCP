<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Project;

enum HostType: string
{
    case Infbyte = 'infbyte';
    case FoundationCustom = 'foundation_custom';
    case Unsupported = 'unsupported';
}
