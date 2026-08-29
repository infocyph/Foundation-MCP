<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Project;

enum HostType: string
{
    case FoundationCustom = 'foundation_custom';

    case Infbyte = 'infbyte';

    case Unsupported = 'unsupported';
}
