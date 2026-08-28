<?php

namespace App\Notifications\Traits;

use App\Models\OwnerCompany;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

trait HasInlineLogo
{
    /**
     * Attach the inline logo to the MailMessage.
     */
    protected function attachInlineLogo(MailMessage $mailMessage): MailMessage
    {
        $ownerCompany = OwnerCompany::first();
        $logoPath = null;
        if ($ownerCompany && ! empty($ownerCompany->logo) && file_exists(public_path($ownerCompany->logo))) {
            $logoPath = public_path($ownerCompany->logo);
        } elseif (file_exists(public_path('images/logo.png'))) {
            $logoPath = public_path('images/logo.png');
        }

        if ($logoPath) {
            $mailMessage->withSymfonyMessage(function (Email $email) use ($logoPath) {
                $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'svg' => 'image/svg+xml',
                    default => 'image/png',
                };
                $part = (new DataPart(new File($logoPath), 'logo.png', $mime))->asInline();
                $part->getHeaders()->setHeaderBody('Id', 'Content-ID', 'logo@laracollab');
                $email->addPart($part);
            });
        }

        return $mailMessage;
    }
}
