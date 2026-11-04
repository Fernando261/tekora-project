<?php

namespace App\Enums;

enum ActivityNotificationEvent: string
{
    case ReservationRequested = 'reservation_requested';
    case ReservationConfirmed = 'reservation_confirmed';
    case ReservationRejected = 'reservation_rejected';
    case ReservationCancelled = 'reservation_cancelled';
    case ReservationCompleted = 'reservation_completed';
    case ReviewPublished = 'review_published';
    case ReviewWithdrawn = 'review_withdrawn';

    public function category(): string
    {
        return str_starts_with($this->value, 'reservation_') ? 'Reservas' : 'Reseñas';
    }

    public function label(): string
    {
        return match ($this) {
            self::ReservationRequested => 'Reserva propuesta', self::ReservationConfirmed => 'Reserva confirmada',
            self::ReservationRejected => 'Reserva rechazada', self::ReservationCancelled => 'Reserva cancelada',
            self::ReservationCompleted => 'Reserva completada', self::ReviewPublished => 'Reseña publicada', self::ReviewWithdrawn => 'Reseña retirada',
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::ReservationRequested => 'Recibiste una propuesta de reserva.',
            self::ReservationConfirmed => 'Se confirmó una reserva.', self::ReservationRejected => 'Se rechazó una propuesta de reserva.',
            self::ReservationCancelled => 'Se canceló una reserva.', self::ReservationCompleted => 'Se marcó una reserva como completada.',
            self::ReviewPublished => 'Tu servicio recibió una reseña verificada.', self::ReviewWithdrawn => 'Una reseña dejó de mostrarse públicamente.',
        };
    }
}
