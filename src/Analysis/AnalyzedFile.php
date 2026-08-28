<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Analysis;

/**
 * @phpstan-type Import array{namespace:?string,kind:string,alias:string,target:string,line:int}
 * @phpstan-type Parameter array{name:string,type:?string,by_reference:bool,variadic:bool,has_default:bool,promoted:?string,readonly:bool}
 * @phpstan-type Declaration array{
 *     kind:string,
 *     name:string,
 *     symbol:string,
 *     line:int,
 *     end_line:int,
 *     visibility:?string,
 *     static:bool,
 *     abstract:bool,
 *     final:bool,
 *     readonly:bool,
 *     type:?string,
 *     parameters:list<Parameter>,
 *     extends:list<string>,
 *     implements:list<string>,
 *     traits:list<string>,
 *     attributes:list<string>,
 *     doc:?string
 * }
 * @phpstan-type Reference array{relationship:string,target:string,line:int,confidence:string}
 * @phpstan-type LiteralArray array{line:int,value:array<array-key,mixed>}
 * @phpstan-type AnalysisError array{code:string,message:string,line:?int}
 */
final readonly class AnalyzedFile
{
    /**
     * @param list<string> $namespaces
     * @param list<Import> $imports
     * @param list<Declaration> $declarations
     * @param list<Reference> $references
     * @param list<LiteralArray> $literalArrays
     * @param list<AnalysisError> $errors
     */
    public function __construct(
        public string $scope,
        public ?string $package,
        public string $path,
        public string $fingerprint,
        public int $bytes,
        public array $namespaces,
        public array $imports,
        public array $declarations,
        public array $references,
        public array $literalArrays,
        public array $errors,
    ) {
    }

    public function valid(): bool
    {
        return $this->errors === [];
    }
}
