<?php

namespace App\Enums;

enum SecurityEventType: string
{
    case ORDER_SPOOFING = 'order_spoofing';
    case WASH_TRADING = 'wash_trading';
    case LAYERING = 'layering';
    case PRICE_MANIPULATION = 'price_manipulation';
    case RAPID_FIRE_SPAM = 'rapid_fire_spam';
    case SUSPICIOUS_PATTERN = 'suspicious_pattern';
    case COORDINATED_TRADING = 'coordinated_trading';

    public function label(): string
    {
        return match ($this) {
            self::ORDER_SPOOFING => 'Order Spoofing',
            self::WASH_TRADING => 'Wash Trading',
            self::LAYERING => 'Layering Attack',
            self::PRICE_MANIPULATION => 'Price Manipulation',
            self::RAPID_FIRE_SPAM => 'Rapid-Fire Spam',
            self::SUSPICIOUS_PATTERN => 'Suspicious Pattern',
            self::COORDINATED_TRADING => 'Coordinated Trading',
        };
    }

    public function baseSeverity(): SecuritySeverity
    {
        return match ($this) {
            self::ORDER_SPOOFING => SecuritySeverity::MEDIUM,
            self::WASH_TRADING => SecuritySeverity::HIGH,
            self::LAYERING => SecuritySeverity::HIGH,
            self::PRICE_MANIPULATION => SecuritySeverity::CRITICAL,
            self::RAPID_FIRE_SPAM => SecuritySeverity::LOW,
            self::SUSPICIOUS_PATTERN => SecuritySeverity::LOW,
            self::COORDINATED_TRADING => SecuritySeverity::MEDIUM,
        };
    }

    public function riskWeight(): float
    {
        return match ($this) {
            self::ORDER_SPOOFING => 15.0,
            self::WASH_TRADING => 25.0,
            self::LAYERING => 20.0,
            self::PRICE_MANIPULATION => 30.0,
            self::RAPID_FIRE_SPAM => 5.0,
            self::SUSPICIOUS_PATTERN => 3.0,
            self::COORDINATED_TRADING => 18.0,
        };
    }
}
