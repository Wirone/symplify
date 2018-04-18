<?php declare(strict_types=1);

namespace Symplify\TokenRunner\Wrapper\FixerWrapper;

use Nette\Utils\Strings;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock as PhpDocumentorDocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Compound;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use Symplify\BetterReflectionDocBlock\DocBlock\ArrayResolver;
use Symplify\BetterReflectionDocBlock\PhpDocParser\PhpDocInfo;
use Symplify\BetterReflectionDocBlock\PhpDocParser\PhpDocInfoPrinter;
use Symplify\BetterReflectionDocBlock\Tag\TolerantParam;
use Symplify\BetterReflectionDocBlock\Tag\TolerantVar;
use Symplify\TokenRunner\Exception\Wrapper\FixerWrapper\MissingWhitespacesFixerConfigException;

final class DocBlockWrapper
{
    /**
     * @var Tokens
     */
    private $tokens;

    /**
     * @var int
     */
    private $position;

    /**
     * @var WhitespacesFixerConfig|null
     */
    private $whitespacesFixerConfig;

    /**
     * @var PhpDocumentorDocBlock
     */
    private $phpDocumentorDocBlock;

//    /**
//     * @var Serializer|null
//     */
//    private $docBlockSerializer;

    /**
     * @var string
     */
    private $originalContent;

    /**
     * @var null|PhpDocInfo
     */
    private $phpDocInfo;

    /**
     * @var PhpDocInfoPrinter
     */
    private $phpDocInfoPrinter;

    public function __construct(
        Tokens $tokens,
        int $position,
        string $content,
        ?DocBlock $docBlock = null,
        ?PhpDocInfo $phpDocInfo = null,
        PhpDocInfoPrinter $phpDocInfoPrinter
    ) {
        $this->tokens = $tokens;
        $this->position = $position;
        $this->originalContent = $content;
        $this->phpDocumentorDocBlock = $docBlock;
        $this->phpDocInfo = $phpDocInfo;
        $this->phpDocInfoPrinter = $phpDocInfoPrinter;
    }

    public function getTokenPosition(): int
    {
        return $this->position;
    }

    public function isSingleLine(): bool
    {
        return substr_count($this->originalContent, PHP_EOL) < 1;
    }

    public function getMultiLineVersion(): string
    {
        $this->ensureWhitespacesFixerConfigIsSet();

        $newLineIndent = $this->whitespacesFixerConfig->getLineEnding() . $this->whitespacesFixerConfig->getIndent();

        return str_replace([' @', '/** ', ' */'], [
            $newLineIndent . ' * @',
            $newLineIndent . '/**',
            $newLineIndent . ' */',
        ], $this->originalContent);
    }

    public function getPhpDocInfo(): PhpDocInfo
    {
        return $this->phpDocInfo;
    }

    public function getArgumentType(string $name): ?string
    {
        $paramTag = $this->findParamTagByName($name);
        if ($paramTag) {
            $paramTagType = $paramTag->getType();

            // distinguish array vs mixed[]
            // false value resolve, @see https://github.com/phpDocumentor/TypeResolver/pull/48
            if ($paramTagType instanceof Array_) {
                return ArrayResolver::resolveArrayType($this->originalContent, $paramTagType, 'param', $name);
            }

            if (! ($paramTagType instanceof Compound)) {
                return $this->clean((string) $paramTagType);
            }

            $types = [];
            foreach ($paramTagType->getIterator() as $singleTag) {
                if ($singleTag instanceof Array_) {
                    $types[] = ArrayResolver::resolveArrayType($this->originalContent, $singleTag, 'param', $name);
                } else {
                    $types[] = (string) $singleTag;
                }
            }

            return implode('|', $types);
        }

        return null;
    }

    /**
     * @return Param[]
     */
    public function getParamTags(): array
    {
        return $this->phpDocumentorDocBlock->getTagsByName('param');
    }

    public function getVarTag(): ?TolerantVar
    {
        return $this->phpDocumentorDocBlock->getTagsByName('var') ?
            $this->phpDocumentorDocBlock->getTagsByName('var')[0]
            : null;
    }

    public function getVarType(): ?string
    {
        $varTag = $this->getVarTag();
        if (! $varTag) {
            return null;
        }

        $varTagType = (string) $varTag->getType();
        $varTagType = trim($varTagType);

        return ltrim($varTagType, '\\');
    }

    public function getArgumentTypeDescription(string $name): string
    {
        $paramTagValue = $this->phpDocInfo->getParamTagValueByName($name);
        if ($paramTagValue) {
            return $paramTagValue->description;
        }

        return '';
    }

    public function removeReturnType(): void
    {
        $phpDocNode = $this->phpDocInfo->getPhpDocNode();

        foreach ($phpDocNode->children as $i => $phpDocChildNode) {
            if ($phpDocChildNode instanceof PhpDocTagNode && $phpDocChildNode->value instanceof ReturnTagValueNode) {
                unset($phpDocNode->children[$i]);
            }
        }
    }

    public function removeParamType(string $name): void
    {
        $phpDocNode = $this->phpDocInfo->getPhpDocNode();
        $paramTagValue = $this->phpDocInfo->getParamTagValueByName($name);

        foreach ($phpDocNode->children as $key => $phpDocChildNode) {
            if (! property_exists($phpDocChildNode, 'value')) {
                continue;
            }

            // process invalid tag values
            if ($phpDocChildNode->value instanceof InvalidTagValueNode) {
                if ($phpDocChildNode->value->value === '$' . $name) {
                    unset($phpDocNode->children[$key]);
                    continue;
                }
            }

            if ($phpDocChildNode->value === $paramTagValue) {
                unset($phpDocNode->children[$key]);
            }
        }
    }

    public function setWhitespacesFixerConfig(WhitespacesFixerConfig $whitespacesFixerConfig): void
    {
        $this->whitespacesFixerConfig = $whitespacesFixerConfig;
    }

    public function isArrayProperty(): bool
    {
        $varTags = $this->phpDocumentorDocBlock->getTagsByName('var');
        if (! count($varTags)) {
            return false;
        }

        /** @var TolerantVar $varTag */
        $varTag = $varTags[0];

        $types = explode('|', trim((string) $varTag->getType()));

        foreach ($types as $type) {
            if (! $this->isIterableType($type)) {
                return false;
            }
        }

        return true;
    }

    public function updateDocBlockTokenContent(): void
    {
        $this->tokens[$this->position] = new Token([T_DOC_COMMENT, $this->getContent()]);
    }

    public function getContent(): string
    {
        return $this->phpDocInfoPrinter->printFormatPreserving($this->phpDocInfo);
    }

    private function isIterableType(string $type): bool
    {
        if (Strings::endsWith($type, '[]')) {
            return true;
        }

        if ($type === 'array') {
            return true;
        }

        return false;
    }

    private function clean(string $content): string
    {
        return ltrim(trim($content), '\\');
    }

    private function findParamTagByName(string $name): ?TolerantParam
    {
        $paramTags = $this->phpDocumentorDocBlock->getTagsByName('param');

        /** @var TolerantParam $paramTag */
        foreach ($paramTags as $paramTag) {
            if ($paramTag->getVariableName() === $name) {
                return $paramTag;
            }
        }

        return null;
    }

    private function ensureWhitespacesFixerConfigIsSet(): void
    {
        if ($this->whitespacesFixerConfig) {
            return;
        }

        throw new MissingWhitespacesFixerConfigException(sprintf(
            '"%s% is not set to "%s". Use %s interface on your Fixer '
            . 'and pass it via `$docBlockWrapper->setWhitespacesFixerConfig()`',
            WhitespacesFixerConfig::class,
            self::class,
            WhitespacesAwareFixerInterface::class
        ));
    }

    public function saveNewPhpDocInfo(): void
    {
        $newDocCommentContent = $this->phpDocInfoPrinter->printFormatPreserving($this->phpDocInfo);
        if ($newDocCommentContent) {
            // create and save new doc comment
            $this->tokens[$this->position] = new Token([T_DOC_COMMENT, $newDocCommentContent]);
            return;
        }

        // remove empty doc
        $this->tokens->clearAt($this->position);
        if ($this->tokens[$this->position - 1]->isWhitespace()) {
            // used from RemoveEmptyDocBlockFixer
            $this->removeExtraWhitespaceAfterRemovedDocBlock();
        }
    }

    private function removeExtraWhitespaceAfterRemovedDocBlock(): void
    {
        $previousToken = $this->tokens[$this->position - 1];
        if ($previousToken->isWhitespace()) {
            $previousWhitespaceContent = $previousToken->getContent();

            $lastLineBreak = strrpos($previousWhitespaceContent, PHP_EOL);
            $newWhitespaceContent = substr($previousWhitespaceContent, 0, $lastLineBreak);
            if ($newWhitespaceContent) {
                $this->tokens[$this->position - 1] = new Token([T_WHITESPACE, $newWhitespaceContent]);
            } else {
                $this->tokens->clearAt($this->position - 1);
            }
        }
    }
}
