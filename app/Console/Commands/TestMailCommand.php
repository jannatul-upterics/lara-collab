<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestMailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test {email? : The recipient email address}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test email using configured SMTP settings (Google SMTP)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $recipient = $this->argument('email') ?? config('mail.from.address');

        if (empty($recipient) || $recipient === 'your_email@gmail.com' || $recipient === 'null') {
            $this->error('Please specify a valid recipient email address: php artisan mail:test user@example.com');

            return Command::FAILURE;
        }

        $this->info("Attempting to send test email to: {$recipient}");
        $this->info('SMTP Host: '.config('mail.mailers.smtp.host'));
        $this->info('SMTP Port: '.config('mail.mailers.smtp.port'));
        $this->info('From Address: '.config('mail.from.address'));

        try {
            Mail::raw('This is a test email sent from LaraCollab using Google SMTP configuration.', function ($message) use ($recipient) {
                $message->to($recipient)
                    ->subject('LaraCollab - Google SMTP Test Email');
            });

            $this->output->success("Test email sent successfully to {$recipient}!");

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to send test email!');
            $this->error('Error Message: '.$e->getMessage());
            $this->newLine();
            $this->warn('Troubleshooting Tips for Google SMTP:');
            $this->line('1. Make sure MAIL_USERNAME in .env is your full Gmail address (e.g., example@gmail.com).');
            $this->line('2. Use a Google "App Password" (16 characters) instead of your regular password.');
            $this->line('   Generate it at: https://myaccount.google.com/apppasswords');
            $this->line('3. Ensure 2-Step Verification is enabled on your Google Account.');
            $this->line('4. Verify MAIL_PORT is 587 with MAIL_ENCRYPTION=tls (or 465 with ssl).');

            return Command::FAILURE;
        }
    }
}
