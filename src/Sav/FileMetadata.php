<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class FileMetadata
{
    /** @var list<string> */
    private array $documents;

    /** @var list<FileAttribute> */
    private array $attributes;

    /** @var list<VariableSet> */
    private array $variableSets;

    /** @var list<MultipleResponseSet> */
    private array $multipleResponseSets;

    /**
     * @param list<string>              $documents
     * @param list<FileAttribute>       $attributes
     * @param list<VariableSet>         $variableSets
     * @param list<MultipleResponseSet> $multipleResponseSets
     */
    public function __construct(
        public ?string $label = null,
        public ?string $weightVariableName = null,
        public ?\DateTimeImmutable $createdAt = null,
        array $documents = [],
        array $attributes = [],
        array $variableSets = [],
        array $multipleResponseSets = [],
    ) {
        if (null !== $this->weightVariableName && '' === trim($this->weightVariableName)) {
            throw new \InvalidArgumentException('A weight variable name cannot be empty.');
        }

        self::assertListOf($documents, 'documents', 'string');
        self::assertListOf($attributes, 'attributes', FileAttribute::class);
        self::assertListOf($variableSets, 'variable sets', VariableSet::class);
        self::assertListOf($multipleResponseSets, 'multiple-response sets', MultipleResponseSet::class);

        $this->documents = $documents;
        $this->attributes = $attributes;
        $this->variableSets = $variableSets;
        $this->multipleResponseSets = $multipleResponseSets;
    }

    /** @return list<string> */
    public function documents(): array
    {
        return $this->documents;
    }

    /** @return list<FileAttribute> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @return list<VariableSet> */
    public function variableSets(): array
    {
        return $this->variableSets;
    }

    /** @return list<MultipleResponseSet> */
    public function multipleResponseSets(): array
    {
        return $this->multipleResponseSets;
    }

    /** @param array<array-key, mixed> $values */
    private static function assertListOf(array $values, string $description, string $type): void
    {
        if (!array_is_list($values)) {
            throw new \InvalidArgumentException(sprintf('File metadata %s must be a list.', $description));
        }

        foreach ($values as $value) {
            $valid = 'string' === $type ? \is_string($value) : $value instanceof $type;
            if (!$valid) {
                throw new \InvalidArgumentException(sprintf('File metadata %s contain an invalid value.', $description));
            }
        }
    }
}
