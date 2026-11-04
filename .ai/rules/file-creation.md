# File Creation

Always use Artisan `make:` commands to create new PHP/Laravel files instead of writing them manually.

This ensures correct namespace, directory placement, boilerplate, and consistency with the framework.

| File type | Command |
| --- | --- |
| Model | `php artisan make:model ModelName` |
| Controller | `php artisan make:controller ControllerName` |
| Migration | `php artisan make:migration name` |
| Seeder | `php artisan make:seeder SeederName` |
| Factory | `php artisan make:factory FactoryName` |
| Livewire component | `php artisan make:livewire component-name` |
| Test | `php artisan make:test --phpunit TestName` |
| Class | `php artisan make:class ClassName` |
| Enum | `php artisan make:enum EnumName` |
| Policy | `php artisan make:policy PolicyName` |
| Request | `php artisan make:request RequestName` |
| Job | `php artisan make:job JobName` |
| Event | `php artisan make:event EventName` |
| Listener | `php artisan make:listener ListenerName` |
| Notification | `php artisan make:notification NotificationName` |
| Mail | `php artisan make:mail MailName` |
| Command | `php artisan make:command CommandName` |

Always pass `--no-interaction` to Artisan commands so they run without prompting.

Only write files manually when no Artisan command exists for that file type (e.g., Blade views, config files, CSS/JS assets).
