<?php

declare(strict_types=1);

namespace GitHubSecurityJira\Jira;

/**
 * Build Atlassian Document Format (ADF) for Jira v3 API
 */
class AdfBuilder
{
    private array $content = [];

    public function __construct()
    {
        // Initialize empty document
    }

    /**
     * Add a heading
     */
    public function addHeading(string $text, int $level = 2): self
    {
        $this->content[] = [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];
        return $this;
    }

    /**
     * Add a paragraph with optional inline formatting
     */
    public function addParagraph(string $text, array $marks = []): self
    {
        $textNode = ['type' => 'text', 'text' => $text];
        if (!empty($marks)) {
            $textNode['marks'] = $marks;
        }

        $this->content[] = [
            'type' => 'paragraph',
            'content' => [$textNode],
        ];
        return $this;
    }

    /**
     * Add a bullet list
     */
    public function addBulletList(array $items): self
    {
        $listItems = array_map(function ($item) {
            // Check if item contains a link pattern [text|url]
            if (preg_match('/(.*)?\[([^\]]+)\|([^\]]+)\](.*)/', $item, $matches)) {
                $prefix = $matches[1] ?? '';
                $linkText = $matches[2];
                $url = $matches[3];
                $suffix = $matches[4] ?? '';

                // Build content with prefix, link, and suffix
                $content = [];

                if (!empty($prefix)) {
                    $content[] = ['type' => 'text', 'text' => $prefix];
                }

                $content[] = [
                    'type' => 'text',
                    'text' => $linkText,
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => ['href' => $url],
                        ],
                    ],
                ];

                if (!empty($suffix)) {
                    $content[] = ['type' => 'text', 'text' => $suffix];
                }

                return [
                    'type' => 'listItem',
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => $content,
                        ],
                    ],
                ];
            }

            return [
                'type' => 'listItem',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => $item],
                        ],
                    ],
                ],
            ];
        }, $items);

        $this->content[] = [
            'type' => 'bulletList',
            'content' => $listItems,
        ];
        return $this;
    }

    /**
     * Add a paragraph with a link
     */
    public function addLink(string $text, string $url): self
    {
        $this->content[] = [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => ['href' => $url],
                        ],
                    ],
                ],
            ],
        ];
        return $this;
    }

    /**
     * Add a code block
     */
    public function addCodeBlock(string $code, string $language = 'text'): self
    {
        $this->content[] = [
            'type' => 'codeBlock',
            'attrs' => ['language' => $language],
            'content' => [
                ['type' => 'text', 'text' => $code],
            ],
        ];
        return $this;
    }

    /**
     * Add raw content (for advanced use)
     */
    public function addRawContent(array $contentNode): self
    {
        $this->content[] = $contentNode;
        return $this;
    }

    /**
     * Build the final ADF document
     */
    public function build(): array
    {
        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $this->content,
        ];
    }

    /**
     * Create ADF from Jira Wiki Markup (simplified conversion)
     * Supports: bullet lists, links, code blocks, headings
     */
    public static function fromWikiMarkup(string $markup): array
    {
        $builder = new self();

        $lines = explode("\n", $markup);
        $currentList = [];
        $inCodeBlock = false;
        $codeBlockContent = [];

        foreach ($lines as $line) {
            // Handle code blocks {noformat}
            if (preg_match('/^\{noformat\}$/', trim($line))) {
                if (!$inCodeBlock) {
                    // Starting code block - flush any pending list
                    if (!empty($currentList)) {
                        $builder->addBulletList($currentList);
                        $currentList = [];
                    }
                    $inCodeBlock = true;
                } else {
                    // Ending code block
                    $builder->addCodeBlock(implode("\n", $codeBlockContent));
                    $codeBlockContent = [];
                    $inCodeBlock = false;
                }
                continue;
            }

            // If in code block, collect lines
            if ($inCodeBlock) {
                $codeBlockContent[] = $line;
                continue;
            }

            $trimmed = trim($line);

            // Empty line - flush current list
            if (empty($trimmed)) {
                if (!empty($currentList)) {
                    $builder->addBulletList($currentList);
                    $currentList = [];
                }
                continue;
            }

            // Check for heading (h1. h2. etc in Jira wiki markup)
            if (preg_match('/^h(\d)\.\s*(.+)$/', $trimmed, $matches)) {
                // Flush current list
                if (!empty($currentList)) {
                    $builder->addBulletList($currentList);
                    $currentList = [];
                }
                $builder->addHeading($matches[2], (int) $matches[1]);
                continue;
            }

            // Check for bullet point (starts with -)
            if (preg_match('/^-\s+(.+)$/', $trimmed, $matches)) {
                $currentList[] = $matches[1];
                continue;
            }

            // Check for double dash bullet (starts with --)
            if (preg_match('/^--\s+(.+)$/', $trimmed, $matches)) {
                $currentList[] = '  ' . $matches[1]; // Indent for sub-item
                continue;
            }

            // Regular paragraph
            if (!empty($currentList)) {
                $builder->addBulletList($currentList);
                $currentList = [];
            }

            // Check if line contains links in wiki format [text|url]
            if (preg_match('/\[([^\]]+)\|([^\]]+)\]/', $trimmed)) {
                // Convert wiki links to ADF in paragraph
                $builder->addParagraph($trimmed);
            } else {
                $builder->addParagraph($trimmed);
            }
        }

        // Flush remaining list
        if (!empty($currentList)) {
            $builder->addBulletList($currentList);
        }

        // Flush remaining code block
        if ($inCodeBlock && !empty($codeBlockContent)) {
            $builder->addCodeBlock(implode("\n", $codeBlockContent));
        }

        return $builder->build();
    }
}
