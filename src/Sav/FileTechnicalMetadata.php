<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class FileTechnicalMetadata
{
    public function __construct(
        public string $sourceFormat = 'sav',
        public ?string $recordType = null,
        public ?string $sourceVersion = null,
        public ?string $provenance = null,
        public string $encoding = 'UTF-8',
        public ?string $productName = null,
        public ?string $rawCreationDate = null,
        public ?string $rawCreationTime = null,
        public ?int $caseCount = null,
        public ?int $nominalCaseSize = null,
        public ?int $layoutCode = null,
        public ?int $compression = null,
        public ?float $compressionBias = null,
        public ?int $machineCode = null,
        public ?int $floatingPointRepresentation = null,
        public ?int $endianness = null,
        public ?int $characterCode = null,
    ) {
        if ('' === trim($this->sourceFormat)) {
            throw new \InvalidArgumentException('Source format cannot be empty.');
        }

        if (null !== $this->recordType && '' === trim($this->recordType)) {
            throw new \InvalidArgumentException('Record type cannot be empty.');
        }

        if (null !== $this->sourceVersion && '' === trim($this->sourceVersion)) {
            throw new \InvalidArgumentException('Source version cannot be empty.');
        }

        if (null !== $this->provenance && '' === trim($this->provenance)) {
            throw new \InvalidArgumentException('Provenance cannot be empty.');
        }

        if ('' === trim($this->encoding)) {
            throw new \InvalidArgumentException('File encoding cannot be empty.');
        }

        if (null !== $this->caseCount && $this->caseCount < 0) {
            throw new \InvalidArgumentException('Case count cannot be negative.');
        }

        if (null !== $this->nominalCaseSize && $this->nominalCaseSize < 0) {
            throw new \InvalidArgumentException('Nominal case size cannot be negative.');
        }

        if (null !== $this->compression && !\in_array($this->compression, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Compression must be 0, 1, or 2.');
        }

        if (null !== $this->endianness && !\in_array($this->endianness, [1, 2], true)) {
            throw new \InvalidArgumentException('Endianness must be 1 or 2.');
        }
    }
}
