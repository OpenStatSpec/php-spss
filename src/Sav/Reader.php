<?php

namespace SPSS\Sav;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Record\Info;
use SPSS\Sav\Record\ValueLabel;
use SPSS\Utils;

class Reader
{
    /**
     * @var Header|null
     */
    public $header;

    /**
     * @var list<Record\Variable>
     */
    public $variables = [];

    /** @var list<Record\Variable> All physical dictionary records, including string continuations. */
    public array $physicalVariables = [];

    /**
     * @var list<ValueLabel>
     */
    public $valueLabels = [];

    /**
     * @var list<string>
     */
    public $documents = [];

    /**
     * @var array<int, Info>
     */
    public $info = [];

    /** @var list<Info> Physical extension records in file order. */
    public array $infoRecords = [];

    /** @var array<int, Info> Semantically merged extension records. */
    public array $mergedInfo = [];

    /**
     * @var list<list<int|float|string|null>>
     */
    public $data = [];

    /**
     * @var int
     */
    public $lastCase = -1;

    /**
     * @var int
     */
    public $dataPosition = -1;

    /**
     * @var Record\Data|null
     */
    public $record;

    private bool $dataRead = false;

    /**
     * Reader constructor.
     */
    private function __construct(
        protected Buffer $_buffer,
        private readonly ?string $source = null,
    ) {
        $this->_buffer->context = $this;
    }

    private function readBodyInternal(): void
    {
        $infoCollection = new Record\InfoCollection();
        $posVar         = 0;
        while (true) {
            $recType = $this->_buffer->readInt();
            if (false === $recType) {
                throw new Exception('Unexpected end of SPSS metadata before the data record.');
            }

            if (Record\Data::TYPE === $recType) {
                break;
            }

            switch ($recType) {
                case Record\Variable::TYPE:
                    $variable               = Record\Variable::fill($this->_buffer);
                    $variable->realPosition = $posVar;
                    $this->variables[]      = $variable;
                    $this->physicalVariables[] = $variable;
                    $posVar++;
                    break;
                case Record\ValueLabel::TYPE:
                    $this->valueLabels[] = Record\ValueLabel::fill($this->_buffer, [
                        'variables' => $this->variables,
                    ]);
                    break;
                case Record\Info::TYPE:
                    $this->info = $infoCollection->fill($this->_buffer);
                    $this->infoRecords = $infoCollection->records;
                    $this->mergedInfo = $infoCollection->mergedData;
                    break;
                case Record\Document::TYPE:
                    $this->documents = Record\Document::fill($this->_buffer)->toArray();
                    break;
                default:
                    throw new Exception(sprintf('Unsupported SPSS record type %d in the metadata body.', $recType));
            }
        }
    }

    /**
     * @param string $file
     */
    public static function fromFile($file): self
    {
        return new self(Buffer::factory(fopen($file, 'rb')), $file);
    }

    /**
     * @param resource|string $str
     */
    public static function fromString($str): self
    {
        return new self(Buffer::factory($str));
    }

    public function readMetaData(): static
    {
        return $this->readHeader()->readBody();
    }

    public function read(): static
    {
        return $this->readHeader()->readBody()->readData();
    }

    public function readDataset(): Dataset
    {
        if (null === $this->header) {
            $this->read();
        } else {
            if (-1 === $this->dataPosition) {
                $this->readBody();
            }

            if (!$this->dataRead) {
                $this->readData();
            }
        }

        return $this->toDataset();
    }

    public function toDataset(bool $includeData = true): Dataset
    {
        if (-1 === $this->dataPosition) {
            throw new \LogicException('Reader metadata must be loaded before converting it to a dataset.');
        }

        if ($includeData && !$this->dataRead) {
            throw new \LogicException('Reader data must be loaded before including rows in a dataset.');
        }

        return new DatasetAssembler()->assemble($this, $includeData);
    }

    public function readHeader(): static
    {
        $this->header = Record\Header::fill($this->_buffer);

        return $this;
    }

    public function readBody(): static
    {
        if ($this->header === null) {
            $this->readHeader();
        }

        // TODO: We need to find a better way to decode the body, because the CharacterEncoding
        // data is not necessary set at the beginning of the body and any string that is set
        // before it is then not decode. So, we need to read twice the body, once to find the
        // encode and another to decode it.
        $headerPosition = $this->_buffer->position();
        $this->readBodyInternal();

        $encodingInfo = $this->info[Record\Info\CharacterEncoding::SUBTYPE] ?? null;
        if ($encodingInfo instanceof Record\Info\CharacterEncoding) {
            $encode = $encodingInfo->value;
            // If is not set assume the UTF-8 encode.
            $encode = $encode !== '' ? $encode : 'UTF-8';
            $this->_buffer->charset = $encode;

            if ($this->_buffer->seek($headerPosition) === 0) {
                $this->valueLabels = [];
                $this->info        = [];
                $this->infoRecords = [];
                $this->mergedInfo  = [];
                $this->documents   = [];
                $this->variables   = [];
                $this->physicalVariables = [];
                $this->readBodyInternal();
            }
        }

        // Excluding the records that are creating only as a consequence of very long string records
        // from the variables computation.
        $veryLongStrings = [];
        if (isset($this->info[Record\Info\VeryLongString::SUBTYPE])) {
            $veryLongStrings = $this->info[Record\Info\VeryLongString::SUBTYPE]->toArray();
        }

        $segmentsCount = 0;
        $tempVars = $this->variables;
        $this->variables = [];
        foreach ($tempVars as $var) {
            // Skip blank records from the variables computation
            if ($var->width !== -1) {
                if ($segmentsCount <= 0) {
                    $segmentsCount = Utils::widthToSegments(
                        $veryLongStrings[$var->name] ?? $var->width,
                    );
                    $this->variables[] = $var;
                }

                $segmentsCount--;
            }
        }

        $this->dataPosition = $this->_buffer->position();

        return $this;
    }

    public function readData(): static
    {
        $this->data = Record\Data::fill($this->_buffer)->toArray();
        $this->dataRead = true;

        return $this;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function rewindCaseIterator(): bool
    {
        if ($this->dataPosition !== -1) {
            $this->lastCase = -1;
            $this->record = null;
            if ($this->_buffer->seek($this->dataPosition) === 0) {
                return true;
            }
        }

        return false;
    }

    public function readCase(): bool
    {
        if ($this->record === null) {
            $this->record = Record\Data::create();
        }

        $this->lastCase++;

        if (($this->lastCase >= 0) && ($this->lastCase < $this->_buffer->context->header->casesCount)) {
            $this->record->readCase($this->_buffer, $this->lastCase);

            return true;
        }

        return false;
    }

    /**
     * @return int
     */
    public function getNumberOfCases()
    {
        return $this->_buffer->context->header->casesCount;
    }

    /**
     * @return int
     */
    public function getCaseNumber()
    {
        return $this->lastCase;
    }

    /**
     * @return array<int, float|string>
     */
    public function getCase()
    {
        return $this->record->getRow();
    }
}
