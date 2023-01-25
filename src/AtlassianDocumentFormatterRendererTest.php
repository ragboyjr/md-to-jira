<?php

namespace Krak\MDToJira;

use PHPUnit\Framework\TestCase;

final class AtlassianDocumentFormatterRendererTest extends TestCase
{
    private string $markdown;
    private ?array $result;

    public function test_rendering_unstyled_paragraph() {
        $this->given_markdown(<<<MD
Unstyled Paragraph
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Unstyled Paragraph']
                    ]
                ]
            ]
        ]);
    }

    public function test_rendering_code_mark() {
        $this->given_markdown(<<<MD
hello `code`
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'hello ',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'code',
                            'marks' => [['type' => 'code']]
                        ]
                    ]
                ]
            ]
        ]);
    }

    public function test_rendering_nested_emphasis() {
        $this->given_markdown(<<<MD
*foo **bar** baz*
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'foo ',
                            'marks' => [['type' => 'em']]
                        ],
                        [
                            'type' => 'text',
                            'text' => 'bar',
                            'marks' => [['type' => 'em'], ['type' => 'strong']]
                        ],
                        [
                            'type' => 'text',
                            'text' => ' baz',
                            'marks' => [['type' => 'em']]
                        ],
                    ]
                ]
            ]
        ]);
    }

    public function test_rendering_paragraph_with_all_styles() {
        $this->given_markdown(<<<MD
`code` *emphasis* **_strong emphasis_** **strong** ~~strike~~ 
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'code', 'marks' => [['type' => 'code']]],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'text', 'text' => 'emphasis', 'marks' => [['type' => 'em']]],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'text', 'text' => 'strong emphasis', 'marks' => [['type' => 'strong'], ['type' => 'em']]],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'text', 'text' => 'strong', 'marks' => [['type' => 'strong']]],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'text', 'text' => 'strike', 'marks' => [['type' => 'strike']]],
                    ]
                ]
            ]
        ]);
    }

    public function test_rendering_multiline_paragraph() {
        $this->given_markdown(<<<MD
Start
Middle
End
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => "Start"],
                        ['type' => 'text', 'text' => "\n"],
                        ['type' => 'text', 'text' => "Middle"],
                        ['type' => 'text', 'text' => "\n"],
                        ['type' => 'text', 'text' => "End"],
                    ]
                ]
            ]
        ]);
    }

    public function test_rendering_hr() {
        $this->given_markdown(<<<MD
Start

----------

Finish
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => "Start"],
                    ]
                ],
                ['type' => 'rule'],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => "Finish"],
                    ]
                ],
            ]
        ]);
    }

    public function test_rendering_heading() {
        $this->given_markdown(<<<MD
# H1
### *H3*
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'content' => [
                        ['type' => 'text', 'text' => "H1"],
                    ],
                    'attrs' => ['level' => 1]
                ],
                [
                    'type' => 'heading',
                    'content' => [
                        ['type' => 'text', 'text' => "H3", 'marks' => [['type' => 'em']]],
                    ],
                    'attrs' => ['level' => 3]
                ],
            ]
        ]);
    }

    public function test_rendering_code_block() {
        $this->given_markdown(<<<MD
```javascript
var foo = {};
var bar = [];
```
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'codeBlock',
                    'content' => [
                        ['type' => 'text', 'text' => "var foo = {};\nvar bar = [];\n"],
                    ],
                    'attrs' => ['language' => 'javascript']
                ],
            ]
        ]);
    }

    public function test_rendering_indented_code_block() {
        $this->given_markdown(<<<MD
    var foo = {};
    var bar = [];
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'codeBlock',
                    'content' => [
                        ['type' => 'text', 'text' => "var foo = {};\nvar bar = [];\n"],
                    ],
                ],
            ]
        ]);
    }

    public function test_rendering_lists() {
        $this->given_markdown(<<<MD
- a
- b

1. c
2. d
MD
);
        $this->when_markdown_is_rendered();
        $this->then_adf_matches([
            'version' => 1,
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'bulletList',
                    'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a']]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'b']]]]],
                    ],
                ],
                [
                    'type' => 'orderedList',
                    'content' => [
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'c']]]]],
                        ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'd']]]]],
                    ],
                ],
            ]
        ]);
    }

    private function given_markdown(string $content) {
        $this->markdown = $content;
    }

    private function when_markdown_is_rendered() {
        $env = new \League\CommonMark\Environment\Environment([]);
        $env->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
        $env->addExtension(new \League\CommonMark\Extension\Attributes\AttributesExtension());
        $env->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
        $parser = new \League\CommonMark\Parser\MarkdownParser($env);
        $doc = $parser->parse($this->markdown);
        $this->result = (new AtlassianDocumentFormatterRenderer())->render($doc, []);
    }

    private function then_adf_matches(array $expected) {
        $this->assertEquals($expected, $this->result);
    }
}
