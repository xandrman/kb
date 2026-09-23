<?php

namespace App\Enums;

/**
 * Personal data masked before indexing (SRS 4.4, FR-8): the type names the placeholder that replaces the value.
 */
enum PersonalDataType: string
{
    case PersonName = 'person_name';
    case Phone = 'phone';
    case Email = 'email';
    case Address = 'address';
    case Passport = 'passport';
    case Snils = 'snils';
    case Inn = 'inn';
    case BirthDate = 'birth_date';
    case BankCard = 'bank_card';

    /**
     * Placeholder word: «[ФИО 1]» keeps the sentence readable for the answering model.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::PersonName => 'ФИО',
            self::Phone => 'ТЕЛЕФОН',
            self::Email => 'EMAIL',
            self::Address => 'АДРЕС',
            self::Passport => 'ПАСПОРТ',
            self::Snils => 'СНИЛС',
            self::Inn => 'ИНН',
            self::BirthDate => 'ДАТА РОЖДЕНИЯ',
            self::BankCard => 'НОМЕР КАРТЫ',
        };
    }
}
