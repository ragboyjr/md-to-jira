<?php

namespace Krak\MDToJira;

use League\CommonMark\Node\{Block, Node, Inline};
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Parser\MarkdownParserInterface;

final class AtlassianDocumentFormatterRenderer
{

    public static function createWithDefault(): self {
        $env = new \League\CommonMark\Environment\Environment([]);
        $env->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
        $env->addExtension(new \League\CommonMark\Extension\Attributes\AttributesExtension());
        $env->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
        $parser = new \League\CommonMark\Parser\MarkdownParser($env);
        return new self($parser);
    }

    /** returns an collection of node results. Each MD node may surface one or more actual ADF nodes */
    public function render(Node $node, array $context = []): array {
        if ($node instanceof Block\Document) {
            return $this->renderDocument($node);
        }
        if ($node instanceof Block\Paragraph) {
            return [$this->renderBlock($node, 'paragraph', [], $context)];
        }
        if ($node instanceof ListBlock) {
            return [$this->renderBlock($node,$node->getListData()->type === ListBlock::TYPE_BULLET ? 'bulletList' : 'orderedList', [], $context)];
        }
        if ($node instanceof ListItem) {
            return [$this->renderBlock($node,'listItem', [], $context)];
        }
        if ($node instanceof Heading) {
            return [$this->renderBlock($node, 'heading', ['level' => $node->getLevel()], $context)];
        }
        if ($node instanceof FencedCode) {
            return [$this->renderCodeBlock($node->getLiteral(), $node->getInfo())];
        }
        if ($node instanceof IndentedCode) {
            return [$this->renderCodeBlock($node->getLiteral(), null)];
        }
        if ($node instanceof ThematicBreak) {
            return [['type' => 'rule']];
        }
        if ($node instanceof Inline\Text) {
            return [$this->renderText($node->getLiteral(), $this->marks($context))];
        }
        if ($node instanceof Inline\Newline) {
            return [$this->renderText("\n", $this->marks($context))];
        }
        if ($node instanceof Code) {
            return [$this->renderCode($node, $this->marks($context))];
        }
        if ($node instanceof Emphasis) {
            return $this->addMarkAndRenderChildren($node, 'em', $context);
        }
        if ($node instanceof Strong) {
            return $this->addMarkAndRenderChildren($node, 'strong', $context);
        }
        if ($node instanceof Strikethrough) {
            return $this->addMarkAndRenderChildren($node, 'strike', $context);
        }

        return [];
    }

    private function marks(array $context): array {
        return $context['marks'] ?? [];
    }

    private function addMarkToContext(string $mark, array $context): array {
        $marks = $this->marks($context);
        $marks[] = $mark;
        $context['marks'] = array_values(array_unique($marks));
        return $context;
    }

    private function renderDocument(Block\Document $doc): array {
        return [
            'version' => 1,
            'type' => 'doc',
            'content' => $this->renderChildren($doc, []),
        ];
    }

    private function renderCodeBlock(string $code, ?string $language): array {
        return array_merge([
            'type' => 'codeBlock',
            'content' => [$this->renderText($code, [])]
        ], $language ? ['attrs' => ['language' => $language]] : []);
    }

    private function renderBlock(Block\AbstractBlock $node, string $type, array $attrs, array $context): array {
        return array_merge([
            'type' => $type,
            'content' => $this->renderChildren($node, $context),
        ], $attrs ? ['attrs' => $attrs] : []);
    }

    private function addMarkAndRenderChildren(Inline\AbstractInline $inline, string $mark, array $context): array {
        return $this->renderChildren($inline, $this->addMarkToContext($mark, $context));
    }

    private function renderText(string $text, array $marks): array {
        $value = [
            'type' => 'text',
            'text' => $text,
        ];
        if ($marks) {
            $value['marks'] = array_map(fn($m) => ['type' => $m], $marks);
        }

        return $value;
    }

    private function renderCode(Code $code, ?array $marks = []): array {
        return $this->renderText($code->getLiteral(), array_filter([
            'code',
            in_array('link', $marks) ? 'link' : null
        ]));
    }

    private function renderChildren(Node $node, array $context): array {
        return array_reduce($node->children(), function(array $acc, Node $node) use ($context) {
            return array_merge($acc, $this->render($node, $context));
        }, []);
    }
}
