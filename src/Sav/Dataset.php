<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class Dataset
{
    /** @var list<list<int|float|string|null>> */
    private array $rows;

    /**
     * A null cell represents numeric system-missing. String nullability is validated by the SAV adapter.
     *
     * @param list<list<int|float|string|null>> $rows
     */
    public function __construct(
        public VariableDictionary $dictionary = new VariableDictionary(),
        array $rows = [],
        public FileMetadata $metadata = new FileMetadata(),
        public FileTechnicalMetadata $technicalMetadata = new FileTechnicalMetadata(),
    ) {
        foreach ($rows as $row) {
            if (\count($row) !== \count($this->dictionary)) {
                throw new \InvalidArgumentException('Every dataset row must contain one value per variable.');
            }
        }

        $this->rows = $rows;
    }

    /** @return list<VariableMetadata> */
    public function variables(): array
    {
        return $this->dictionary->variables();
    }

    public function variable(string $name): ?VariableMetadata
    {
        return $this->dictionary->variable($name);
    }

    /** @return list<list<int|float|string|null>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return list<int|float|string|null> */
    public function row(int $index): array
    {
        if (!isset($this->rows[$index])) {
            throw new \OutOfBoundsException(sprintf('Dataset row %d does not exist.', $index));
        }

        return $this->rows[$index];
    }

    public function rowCount(): int
    {
        return \count($this->rows);
    }
}
