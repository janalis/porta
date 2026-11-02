<?php

declare(strict_types=1);

namespace Porta\Tests\Support\Screenshots;

use Porta\Config\IdeConfig;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The project every docs screenshot is taken in: a small, ordinary PHP app, so
 * a reader sees the IDE over code that looks like theirs — and a tree named
 * `acme-shop`, not a temp directory.
 *
 * Kept as strings rather than files under fixtures/: the linter would grade a
 * real `.php` file, and this code only has to look right, not pass the gates.
 */
final class ShopProject
{
    public const string NAME = 'acme-shop';

    /** What the status bar names the language server: the one Porta bundles. */
    public const string SERVER = 'phpantom';

    /**
     * The project, its language server the scripted one in
     * `screenshot-lsp-server.php` — reached through a wrapper named after
     * {@see SERVER}, since the status bar names a server by its script.
     *
     * The server is configured in a PORTA_HOME of the project's own rather
     * than in a `.porta.json`, which the tree would show. A host therefore uses
     * {@see \Porta\Tests\Support\PreservesPortaHome}.
     *
     * @param array<string, string> $extra  more files, or replacements, for one scenario
     * @param array<string, mixed>  $config more global settings, for one scenario
     */
    public static function create(array $extra = [], array $config = []): TempProject
    {
        $project = new TempProject([...self::files(), ...$extra], name: self::NAME);
        $root = \dirname($project->dir);
        $wrapper = $root.'/'.self::SERVER.'.php';
        $fs = new Filesystem();
        $fs->dumpFile($wrapper, "<?php\nrequire ".\var_export(\dirname(__DIR__).'/screenshot-lsp-server.php', return: true).";\n");
        $fs->dumpFile($root.'/home/.porta/config.json', \json_encode(
            [
                'lsp' => \PHP_BINARY.' '.$wrapper,
                // The default plugins, and syntax highlighting, which is opt-in.
                'enabledPlugins' => [...IdeConfig::DEFAULT_ENABLED_PLUGINS, 'tempest'],
                ...$config,
            ],
            \JSON_THROW_ON_ERROR,
        ));
        // "This machine just looked": no tool asks a package index if it is outdated.
        $fs->copy((string) \PORTA_TEST_PRISTINE_PORTA_HOME.'/.porta/tool-updates.json', $root.'/home/.porta/tool-updates.json');
        \putenv('PORTA_HOME='.$root.'/home');

        return $project;
    }

    /** @return array<string, string> */
    public static function files(): array
    {
        return [
            'composer.json' => <<<'JSON'
                {
                    "name": "acme/shop",
                    "type": "project",
                    "require": {
                        "php": ">=8.4"
                    },
                    "autoload": {
                        "psr-4": { "Acme\\Shop\\": "src/" }
                    }
                }

                JSON,
            'README.md' => "# Acme Shop\n\nOrders, prices and the checkout.\n",
            'src/Order/Order.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Order;

                use Acme\Shop\Pricing\Money;

                final class Order
                {
                    /** @var list<OrderLine> */
                    private array $lines = [];

                    public function __construct(
                        public readonly string $reference,
                        public readonly Customer $customer,
                    ) {
                    }

                    public function add(Product $product, int $quantity = 1): void
                    {
                        if ($quantity < 1) {
                            throw new \InvalidArgumentException('Quantity must be positive.');
                        }

                        $this->lines[] = new OrderLine($product, $quantity);
                    }

                    public function total(): Money
                    {
                        $total = Money::zero('EUR');
                        foreach ($this->lines as $line) {
                            $total = $total->add($line->subtotal());
                        }

                        return $total;
                    }

                    public function isEmpty(): bool
                    {
                        return [] === $this->lines;
                    }
                }

                PHP,
            'src/Order/OrderLine.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Order;

                use Acme\Shop\Pricing\Money;

                final readonly class OrderLine
                {
                    public function __construct(
                        public Product $product,
                        public int $quantity,
                    ) {
                    }

                    public function subtotal(): Money
                    {
                        return $this->product->price->times($this->quantity);
                    }
                }

                PHP,
            'src/Order/Customer.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Order;

                final readonly class Customer
                {
                    public function __construct(
                        public string $name,
                        public string $email,
                    ) {
                    }
                }

                PHP,
            'src/Order/Product.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Order;

                use Acme\Shop\Pricing\Money;

                final readonly class Product
                {
                    public function __construct(
                        public string $sku,
                        public string $name,
                        public Money $price,
                    ) {
                    }
                }

                PHP,
            'src/Pricing/Money.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Pricing;

                final readonly class Money
                {
                    private function __construct(
                        public int $cents,
                        public string $currency,
                    ) {
                    }

                    public static function zero(string $currency): self
                    {
                        return new self(0, $currency);
                    }

                    public static function of(int $cents, string $currency): self
                    {
                        return new self($cents, $currency);
                    }

                    public function add(self $other): self
                    {
                        return new self($this->cents + $other->cents, $this->currency);
                    }

                    public function times(int $factor): self
                    {
                        return new self($this->cents * $factor, $this->currency);
                    }

                    public function format(): string
                    {
                        return \sprintf('%s %.2f', $this->currency, $this->cents / 100);
                    }
                }

                PHP,
            'src/Checkout/CheckoutController.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Checkout;

                use Acme\Shop\Order\Order;

                final class CheckoutController
                {
                    public function __construct(
                        private readonly PaymentGateway $payments,
                    ) {
                    }

                    public function __invoke(Order $order): string
                    {
                        if ($order->isEmpty()) {
                            return 'Your basket is empty.';
                        }

                        // TODO: send the receipt by email
                        $receipt = $this->payments->charge($order->customer, $order->total());

                        return \sprintf('Paid %s — receipt %s', $order->total()->format(), $receipt);
                    }
                }

                PHP,
            'src/Checkout/PaymentGateway.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Checkout;

                use Acme\Shop\Order\Customer;
                use Acme\Shop\Pricing\Money;

                interface PaymentGateway
                {
                    /** Charge the customer and return the receipt number. */
                    public function charge(Customer $customer, Money $amount): string;
                }

                PHP,
            'tests/OrderTest.php' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace Acme\Shop\Tests;

                use Acme\Shop\Order\Customer;
                use Acme\Shop\Order\Order;
                use Acme\Shop\Order\Product;
                use Acme\Shop\Pricing\Money;
                use PHPUnit\Framework\TestCase;

                final class OrderTest extends TestCase
                {
                    public function testTheTotalAddsEveryLine(): void
                    {
                        $order = new Order('A-1001', new Customer('Ada', 'ada@example.com'));
                        $order->add(new Product('MUG', 'Mug', Money::of(1200, 'EUR')), 2);
                        $order->add(new Product('TEE', 'T-shirt', Money::of(2500, 'EUR')));

                        self::assertSame(4900, $order->total()->cents);
                    }
                }

                PHP_WRAP,
        ];
    }
}
