<?php

use JiraRestApi\Configuration\{AbstractConfiguration, ArrayConfiguration};
use JiraRestApi\Issue\DescriptionV3;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Project\ProjectService;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Node\Node;
use Krak\Fun\{f, c};

require_once __DIR__ . '/vendor/autoload.php';

function jiraBasicAuthFromEnv(): string {
    return getevnv('JIRA_BASIC_AUTH');
}

function createClient(): \Symfony\Contracts\HttpClient\HttpClientInterface {
    return \Symfony\Component\HttpClient\HttpClient::createForBaseUri('https://sgtech.atlassian.net/rest/api/3/', [
        'auth_basic' => jiraBasicAuthFromEnv()
    ]);
}

function createAgileClient(): \Symfony\Contracts\HttpClient\HttpClientInterface {
    return \Symfony\Component\HttpClient\HttpClient::createForBaseUri('https://sgtech.atlassian.net/rest/agile/1.0/', [
        'auth_basic' => jiraBasicAuthFromEnv()
    ]);
}

$client = createClient();

final class SGJiraCustomFields {
    const EPIC_NAME = 'customfield_10011';
    const EPIC_LINK = 'customfield_10014';
    const WORK_CATEGORIZATION = 'customfield_10108';
    const SPRINT = 'customfield_10021';
    const STORY_POINTS = 'customfield_10026';
}

final class SGJiraBoards {
    const AF1_SCRUM = 53;
}

/** Representation of jira issue parsed out from markdown */
final class ParsedJiraIssue {
    public function __construct(
        public string $project,
        public string $summary,
        public string $issueType,
        public array $descriptionInADF,
        public ?string $workCategorization = null,
        public ?int $storyPoints = null,
        public ?string $jiraIssueKey = null,
        public ?string $jiraEpicIssueKey = null,
        public ?string $epicName = null,
    ) {}

    public static function fromGroupedMarkdown(string $project, string $issueType, GroupedMarkdown $md, ?self $epic = null): self {
        return new ParsedJiraIssue(
            $project,
            $md->title(),
            $issueType,
            $md->toADF(),
            $md->heading->data->get('attributes.workCategorization', $epic?->workCategorization),
            $md->heading->data->get('attributes.storyPoints', null),
            $md->heading->data->get('attributes.jiraIssueKey', null),
            $epic?->jiraIssueKey,
            $md->heading->data->get('attributes.epicName', null),
        );
    }
}

final class ParsedDocument {
    public function __construct(
        public ParsedJiraIssue $epic,
        /** @var ParsedJiraIssue[] */
        public array $issues,
    ) {}
}

/** Include the heading and all of the content related to that heading */
final class GroupedMarkdown
{
    public function __construct(
        public Heading $heading,
        public array $body
    ) {}

    public function title(): string {
        return markdownNodesToMarkdownString(...$this->heading->children());
    }

    public function toDocument(): \League\CommonMark\Node\Block\Document {
        $doc = new \League\CommonMark\Node\Block\Document();
        $doc->replaceChildren(array_map(fn($node) => clone $node, $this->body));
        return $doc;
    }

    public function toADF(): array {
        return (new \Krak\MDToJira\AtlassianDocumentFormatterRenderer())->render($this->toDocument());
    }

    public static function createAndDetach(Heading $heading, array $body): self {
        $heading->detach();
        return new self($heading, array_map(function(Node $node) {
            $node->detach();
            return $node;
        }, $body));
    }
}

function markdownNodesToMarkdownString(Node ...$nodes): string {
    return implode(array_map(function(Node $node) {
        if ($node instanceof \League\CommonMark\Node\Inline\DelimitedInterface) {
            return $node->getOpeningDelimiter() . markdownNodesToMarkdownString(...$node->children())  . $node->getClosingDelimiter();
        }
        if ($node instanceof \League\CommonMark\Extension\CommonMark\Node\Inline\Code) {
            return '`' . $node->getLiteral() .'`';
        }
        if ($node instanceof \League\CommonMark\Node\Inline\Text) {
            return $node->getLiteral();
        }
        if ($node instanceof Heading) {
            return str_repeat('#', $node->getLevel()) . ' ' . markdownNodesToMarkdownString(...$node->children());
        }

        return markdownNodesToMarkdownString(...$node->children());
    }, $nodes));
}

function markdownParser(): \League\CommonMark\Parser\MarkdownParser {
    $env = new \League\CommonMark\Environment\Environment([]);
    $env->addExtension(new \League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension());
    $env->addExtension(new \League\CommonMark\Extension\Attributes\AttributesExtension());
    $env->addExtension(new \League\CommonMark\Extension\GithubFlavoredMarkdownExtension());
    return new \League\CommonMark\Parser\MarkdownParser($env);
}

function parseMarkdownDocumentToJiraIssues(string $content, string $project): ParsedDocument {
    $doc = markdownParser()->parse($content);

    $groups = groupByHeadings(1, $doc->children());
    /** @var GroupedMarkdown $epic */
    $epic = f\head($groups);

    $tasks = findAndGroupSecondHeader($groups, 'Tasks');
    $stories = findAndGroupSecondHeader($groups, 'Stories');

    $epic = ParsedJiraIssue::fromGroupedMarkdown($project, 'Epic', $epic);

    return new ParsedDocument($epic, f\toArray(f\chain(
        array_map(fn(GroupedMarkdown $md) => ParsedJiraIssue::fromGroupedMarkdown($project, 'Task', $md, $epic), $tasks),
        array_map(fn(GroupedMarkdown $md) => ParsedJiraIssue::fromGroupedMarkdown($project, 'Story', $md, $epic), $stories),
    )));
}

/** @return GroupedMarkdown[] */
function findAndGroupSecondHeader(array $groups, string $header): array {
    return f\compose(
        c\head,
        c\map(fn(GroupedMarkdown $group) => groupByHeadings(2, $group->body)),
        c\filter(fn(GroupedMarkdown $group) => strcasecmp($group->title(), $header) === 0)
    )($groups) ?? [];
}

function publishTasks(string $sprintName, bool $dump = false) {
    $httpClient = createClient();
    $doc = parseMarkdownDocumentToJiraIssues(file_get_contents('test.md'), 'B20');

    $sprintId = findSprintId(SGJiraBoards::AF1_SCRUM,$sprintName);

    // dump($doc->epic->epicName);return;
    // // upsertJiraIssue($httpClient, $doc->epic);
    // return;

    if ($dump) {
        dump($doc->issues);
        return;
    }

    /** @var ParsedJiraIssue $task */
    foreach ($doc->issues as $issue) {
        upsertJiraIssue($httpClient, $issue, $sprintId);
    }
}

function upsertJiraIssue(\Symfony\Contracts\HttpClient\HttpClientInterface $client, ParsedJiraIssue $issue, ?int $sprintId = null): void {
    $isNew = $issue->jiraIssueKey === null;

    $res = $client->request($isNew ? 'POST' : 'PUT', $isNew ? 'issue' : 'issue/'.$issue->jiraIssueKey, [
        'json' => [
            'fields' => array_merge(
                [
                    'summary' => $issue->summary,
                    'project' => [
                        'key' => $issue->project,
                    ],
                    'issuetype' => ['name' => $issue->issueType],
                    SGJiraCustomFields::WORK_CATEGORIZATION => ['value' => $issue->workCategorization],
                    SGJiraCustomFields::EPIC_LINK => $issue->jiraEpicIssueKey, // epic link
                    'description' => $issue->descriptionInADF,
                ],
                $issue->epicName ? [
                    SGJiraCustomFields::EPIC_NAME => $issue->epicName,
                ] : [],
                $issue->storyPoints !== null ? [
                    SGJiraCustomFields::STORY_POINTS => $issue->storyPoints
                ] : [],
                $sprintId !== null ? [
                    SGJiraCustomFields::SPRINT => $sprintId,
                ] : [],
            ),
        ]
    ]);

    if ($res->getStatusCode() >= 400) {
        dump([$issue->jiraIssueKey, $res->toArray(false)]);
        return;
    }

    if ($isNew) {
        dump(['new' => $res->toArray(false)['key']]);
    } else {
        dump([$res->getStatusCode(), $issue->jiraIssueKey]);
    }
}

/** @return GroupedMarkdown[] */
function groupByHeadings(int $level, array $nodes): array {
    $groups = [];
    $currentHeading = null;
    $body = [];
    foreach ($nodes as $child) {
        if ($currentHeading === null && $child instanceof Heading && $child->getLevel() === $level) {
            $currentHeading = $child;
        } else if ($currentHeading !== null && (!$child instanceof Heading || $child->getLevel() !== $level)) {
            $body[] = $child;
        } else if ($currentHeading !== null && $child instanceof Heading && $child->getLevel() === $level) {
            $groups[] = GroupedMarkdown::createAndDetach($currentHeading, $body);
            $currentHeading = $child;
            $body = [];
        }
    }

    $groups[] = GroupedMarkdown::createAndDetach($currentHeading, $body);
    return $groups;
}


function findSprintId(int $board, string $sprintName): int {
    $res = createAgileClient()->request('GET', "board/{$board}/sprint", ['query' => ['state' => 'future,active']])->toArray(false);

    foreach ($res['values'] as $sprint) {
        if (strcasecmp($sprint['name'], $sprintName) === 0) {
            return $sprint['id'];
        }
    }

    throw new \RuntimeException('Could not find sprint: ' . $sprintName);
}

// publishTasks('AF1 Sprint 42');

// dump(createAgileClient()->request('GET', 'board/53/sprint', ['query' => ['state' => 'future,active']])->toArray(false));

// dump(createClient()->request('GET', 'issue/B20-4342')->toArray(false));

//
//dump($issue->toArray());
//$res = $iss->create($issue);
//
//dump($res);
//
///*
// * customfield_10014-field-label - epic link for jira
//customfield_10108 - Work categorization
// */
