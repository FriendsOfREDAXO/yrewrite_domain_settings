<?php

namespace FriendsOfRedaxo\DomainSettings\Tests;

use FriendsOfRedaxo\DomainSettings\Import\MappedField;
use FriendsOfRedaxo\DomainSettings\Import\Mapper;
use FriendsOfRedaxo\DomainSettings\Import\SourceField;
use FriendsOfRedaxo\DomainSettings\Test\AbstractSuite;
use FriendsOfRedaxo\DomainSettings\Test\Assert;

/**
 * Translating global_settings definitions and values into YForm's shape.
 *
 * Runs without global_settings installed: the mapper is pure translation, so
 * the fields it is given are built here rather than read from a database.
 */
class ImportSuite extends AbstractSuite
{
    private Mapper $mapper;

    public function setUp(): void
    {
        $this->mapper = new Mapper();
    }

    public function getTitle(): string
    {
        return 'Import (global_settings)';
    }

    /** The prefix global_settings requires is not part of the name over here. */
    public function testPrefixIsStripped(): void
    {
        $field = new SourceField(name: 'glob_company', title: 'Firma', typeId: SourceField::TYPE_TEXT);

        Assert::same('company', $field->getKey());

        // A name without the prefix stays as it is rather than losing five
        // characters.
        $plain = new SourceField(name: 'company', title: 'Firma', typeId: SourceField::TYPE_TEXT);
        Assert::same('company', $plain->getKey());
    }

    public function testTextBecomesText(): void
    {
        $mapped = $this->mapper->map(
            new SourceField(name: 'glob_company', title: 'Firma', typeId: SourceField::TYPE_TEXT, default: 'Muster'),
        );

        Assert::same(MappedField::STATUS_AUTOMATIC, $mapped->status);
        Assert::same('text', $mapped->targetType);
        Assert::same('company', $mapped->definition['name']);
        Assert::same('Firma', $mapped->definition['label']);
        Assert::same('Muster', $mapped->definition['default']);
    }

    /**
     * The direction of the option list is the one thing that must not be got
     * wrong: global_settings writes `value:Label`, YForm reads `{"Label": "value"}`.
     * Turning it around leaves every option looking right while no stored
     * value matches any more.
     */
    public function testOptionListKeepsValuesAndLabelsApart(): void
    {
        $mapped = $this->mapper->map(new SourceField(
            name: 'glob_salutation',
            title: 'Anrede',
            typeId: SourceField::TYPE_SELECT,
            params: 'herr:Herr|frau:Frau',
        ));

        Assert::same('choice', $mapped->targetType);

        $choices = $this->decodeChoices($mapped);

        Assert::same('herr', $choices['Herr'] ?? null, 'the label is the key, the value is the value');
        Assert::same('frau', $choices['Frau'] ?? null);
    }

    /** An option without a colon is its own label and its own value. */
    public function testOptionWithoutLabel(): void
    {
        $mapped = $this->mapper->map(new SourceField(
            name: 'glob_size',
            title: 'Größe',
            typeId: SourceField::TYPE_SELECT,
            params: 'klein|groß',
        ));

        $choices = $this->decodeChoices($mapped);

        Assert::same('klein', $choices['klein'] ?? null);
        Assert::same('groß', $choices['groß'] ?? null);
    }

    /** A checkbox with one unkeyed option is global_settings' boolean. */
    public function testSingleCheckboxBecomesCheckbox(): void
    {
        $field = new SourceField(
            name: 'glob_maintenance',
            title: 'Wartung',
            typeId: SourceField::TYPE_CHECKBOX,
            params: 'Aktiv',
        );

        Assert::true($field->isBoolean());
        Assert::same('checkbox', $this->mapper->map($field)->targetType);
        Assert::same('1', $this->mapper->convertValue($field, '|true|'));
        Assert::same('0', $this->mapper->convertValue($field, ''), 'empty stays switched off');
    }

    /** A checkbox with a list of options is a choice with several values. */
    public function testCheckboxListBecomesChoice(): void
    {
        $field = new SourceField(
            name: 'glob_areas',
            title: 'Bereiche',
            typeId: SourceField::TYPE_CHECKBOX,
            params: 'a:Bereich A|b:Bereich B',
        );

        Assert::false($field->isBoolean());

        $mapped = $this->mapper->map($field);

        Assert::same('choice', $mapped->targetType);
        Assert::same(1, $mapped->definition['multiple']);
        Assert::same(1, $mapped->definition['expanded']);
    }

    /** Several values: pipes over there, commas over here. */
    public function testPipesBecomeCommas(): void
    {
        $field = new SourceField(name: 'glob_areas', title: 'Bereiche', typeId: SourceField::TYPE_CHECKBOX, params: 'a:A|b:B');

        Assert::same('a,c', $this->mapper->convertValue($field, '|a|c|'));
        Assert::same('a', $this->mapper->convertValue($field, '|a|'));
        Assert::same('', $this->mapper->convertValue($field, ''));
    }

    /** The older separator still turns up in installations never re-saved. */
    public function testLegacySeparator(): void
    {
        $field = new SourceField(name: 'glob_areas', title: 'Bereiche', typeId: SourceField::TYPE_CHECKBOX, params: 'a:A|b:B');

        Assert::same('a,b', $this->mapper->convertValue($field, 'a|+|b'));
    }

    /** Dates are unix timestamps over there, SQL dates over here. */
    public function testDatesAreConverted(): void
    {
        $date = new SourceField(name: 'glob_founded', title: 'Gegründet', typeId: SourceField::TYPE_DATE);
        $datetime = new SourceField(name: 'glob_next', title: 'Termin', typeId: SourceField::TYPE_DATETIME);

        $timestamp = (string) mktime(14, 30, 0, 5, 17, 1999);

        Assert::same('1999-05-17', $this->mapper->convertValue($date, $timestamp));
        Assert::same('1999-05-17 14:30:00', $this->mapper->convertValue($datetime, $timestamp));
    }

    /**
     * Zero means "not set" over there, and global_settings shows an empty
     * field for it - it must not become a date in 1970.
     */
    public function testZeroTimestampIsNotADate(): void
    {
        $date = new SourceField(name: 'glob_founded', title: 'Gegründet', typeId: SourceField::TYPE_DATE);

        Assert::null($this->mapper->convertValue($date, '0'));
        Assert::null($this->mapper->convertValue($date, 'kein Datum'));
    }

    /** Media and link lists are comma separated on both sides. */
    public function testListsKeepTheirFormat(): void
    {
        $media = new SourceField(name: 'glob_gallery', title: 'Galerie', typeId: SourceField::TYPE_MEDIALIST);
        $mapped = $this->mapper->map($media);

        Assert::same('be_media', $mapped->targetType);
        Assert::same(1, $mapped->definition['multiple']);
        Assert::same('a.jpg,b.jpg', $this->mapper->convertValue($media, 'a.jpg,b.jpg'));

        $link = $this->mapper->map(new SourceField(name: 'glob_links', title: 'Links', typeId: SourceField::TYPE_LINKLIST));
        Assert::same('be_link', $link->targetType);
        Assert::same(1, $link->definition['multiple']);
    }

    /** An option list from SQL is carried over, but the editor has to hear about it. */
    public function testSqlOptionListIsReported(): void
    {
        $field = new SourceField(
            name: 'glob_article',
            title: 'Artikel',
            typeId: SourceField::TYPE_SELECT,
            params: 'SELECT name, id FROM rex_article',
        );

        Assert::true($field->hasSqlOptions());

        $mapped = $this->mapper->map($field);

        Assert::same(MappedField::STATUS_MANUAL, $mapped->status);
        Assert::true([] !== $mapped->notes, 'a manual field has to say why');
        // The query goes across unchanged - rewriting it would mean parsing SQL.
        Assert::same('SELECT name, id FROM rex_article', $mapped->definition['choices']);
    }

    /** A colour picker keeps its value but loses its widget. */
    public function testColourPickerNeedsAttention(): void
    {
        $mapped = $this->mapper->map(
            new SourceField(name: 'glob_accent', title: 'Akzent', typeId: SourceField::TYPE_COLORPICKER, default: '#c00'),
        );

        Assert::same(MappedField::STATUS_MANUAL, $mapped->status);
        Assert::same('text', $mapped->targetType);
        Assert::same('#c00', $mapped->definition['default']);
    }

    /** A legend structures the form on both sides. */
    public function testLegendBecomesFieldset(): void
    {
        $field = new SourceField(name: 'glob_display', title: 'Darstellung', typeId: SourceField::TYPE_LEGEND);

        Assert::false($field->hasValue(), 'a legend carries no value');
        Assert::same('fieldset', $this->mapper->map($field)->targetType);
    }

    /** A tab over there is a table over here - that is a decision, not a conversion. */
    public function testTabIsSkipped(): void
    {
        $mapped = $this->mapper->map(
            new SourceField(name: 'glob_more', title: 'Weiteres', typeId: SourceField::TYPE_TAB),
        );

        Assert::true($mapped->isSkipped());
        Assert::false($mapped->carriesValue());
    }

    /** A type this installation added itself cannot be guessed at. */
    public function testUnknownTypeIsSkipped(): void
    {
        $mapped = $this->mapper->map(new SourceField(name: 'glob_own', title: 'Eigen', typeId: 99));

        Assert::true($mapped->isSkipped());
        Assert::true([] !== $mapped->notes);
    }

    /**
     * The option list as YForm stores it: JSON, label as key.
     *
     * @return array<mixed, mixed>
     */
    private function decodeChoices(MappedField $mapped): array
    {
        $choices = json_decode((string) $mapped->definition['choices'], true);

        // Cast rather than a check: a malformed list is an empty one here, and
        // the assertion that follows says what was expected instead.
        return (array) $choices;
    }
}
