<?php

namespace App\Domain\Schema;

/**
 * Catálogo de tipos de campo (§4 del plan).
 *
 * La Fase 1 implementó los ocho básicos y la Fase 4 los de rol. Cómo se guarda
 * y se sanea el valor de cada uno: App\Domain\Sheet\FieldValue. Qué calcula:
 * App\Domain\Sheet\FieldDerivation. Cómo se pinta: resources/views/fields.
 */
enum FieldType: string
{
    // --- básicos (Fase 1) ---
    case Heading = 'heading';
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Attribute = 'attribute';
    case Computed = 'computed';

    // --- específicos de rol (Fase 4) ---
    case Multiselect = 'multiselect';
    case Tags = 'tags';
    case Color = 'color';
    case Image = 'image';
    case Resource = 'resource';
    case Track = 'track';
    case Clock = 'clock';
    case Proficiency = 'proficiency';
    case DiceButton = 'dice_button';
    case Counter = 'counter';
    case Repeater = 'repeater';
    case DerivedList = 'derived_list';
    case Reference = 'reference';
    case Currency = 'currency';
    case Progress = 'progress';
    case Portrait = 'portrait';

    public function label(): string
    {
        return match ($this) {
            self::Heading => 'Encabezado',
            self::Text => 'Texto corto',
            self::Textarea => 'Texto largo',
            self::Number => 'Número',
            self::Select => 'Lista desplegable',
            self::Checkbox => 'Casilla',
            self::Attribute => 'Atributo (valor + modificador)',
            self::Computed => 'Calculado (fórmula)',
            self::Multiselect => 'Selección múltiple',
            self::Tags => 'Etiquetas',
            self::Color => 'Color',
            self::Image => 'Imagen',
            self::Resource => 'Recurso (actual / máximo)',
            self::Track => 'Marcas de estrés',
            self::Clock => 'Reloj de progreso',
            self::Proficiency => 'Competencia',
            self::DiceButton => 'Botón de tirada',
            self::Counter => 'Contador',
            self::Repeater => 'Tabla de filas',
            self::DerivedList => 'Lista derivada',
            self::Reference => 'Referencia a otra hoja',
            self::Currency => 'Monedas',
            self::Progress => 'Barra de progreso',
            self::Portrait => 'Retrato',
        };
    }

    /** Agrupación para la paleta del constructor. */
    public function group(): string
    {
        return match ($this) {
            self::Heading, self::Text, self::Textarea, self::Number,
            self::Select, self::Checkbox, self::Multiselect, self::Tags,
            self::Color, self::Image => 'Básicos',
            default => 'Rol',
        };
    }

    /**
     * Todos menos `reference`: enlazar con otra hoja necesita las mesas
     * (Fase 6) para saber qué hojas puede ver quien la rellena.
     */
    public function isImplemented(): bool
    {
        return $this !== self::Reference;
    }

    /** Los decorativos no guardan valor y no pueden usarse en fórmulas. */
    public function storesValue(): bool
    {
        return ! in_array($this, [self::Heading, self::DiceButton], true);
    }

    /** El valor lo produce una fórmula, no el usuario. */
    public function isDerived(): bool
    {
        return $this === self::Computed;
    }

    /**
     * Valor inicial cuando el campo no define default. Es la materia prima:
     * CompiledSchema::defaultData() lo pasa por FieldValue::normalize(), que
     * le da la forma completa según la configuración (las casillas de un
     * track, las monedas de una bolsa…).
     */
    public function emptyValue(): mixed
    {
        return match ($this) {
            self::Number, self::Attribute, self::Counter,
            self::Clock, self::Progress => 0,
            self::Checkbox => false,
            self::Multiselect, self::Tags, self::Repeater, self::Track,
            self::Currency, self::DerivedList, self::Proficiency => [],
            self::Resource => ['current' => 0, 'max' => 0, 'temp' => 0],
            self::Heading, self::DiceButton, self::Computed,
            self::Image, self::Portrait => null,
            default => '',
        };
    }

    /**
     * Propiedades derivadas que el campo expone a las fórmulas además de su
     * valor: `@fuerza.mod`, `@pv.current`… Las que no salen del propio valor
     * las calcula FieldDerivation.
     */
    public function derivedProperties(): array
    {
        return match ($this) {
            self::Attribute => ['mod'],
            self::Resource => ['current', 'max', 'temp', 'pct'],
            self::Track => ['boxes', 'marked'],
            self::Progress => ['level', 'next', 'pct'],
            self::Proficiency => ['level', 'misc', 'bonus'],
            self::Currency => ['total'],
            default => [],
        };
    }

    /** @return array<int,self> */
    public static function implemented(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isImplemented()));
    }
}
