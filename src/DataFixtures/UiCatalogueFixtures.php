<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\ReadBook;
use App\Entity\Tag;
use App\Enum\Category;
use App\Repository\UserRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * 23 books (two pages of 20), Polish diacritics in titles and author names, tags, and a few read books with ratings and comments.
 */
final class UiCatalogueFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    /** @var list<array{string, string, string, Category, int}> title, first name, last name, category, year */
    private const BOOKS = [
        ['Solaris', 'Stanisław', 'Lem', Category::Novel, 1961],
        ['Cyberiada', 'Stanisław', 'Lem', Category::Novel, 1965],
        ['Pan Tadeusz', 'Adam', 'Mickiewicz', Category::Poetry, 1834],
        ['Dziady', 'Adam', 'Mickiewicz', Category::Poetry, 1832],
        ['Lalka', 'Bolesław', 'Prus', Category::Novel, 1890],
        ['Chłopi', 'Władysław', 'Reymont', Category::Novel, 1904],
        ['Wiedźmin: Ostatnie życzenie', 'Andrzej', 'Sapkowski', Category::Fantasy, 1993],
        ['Krew elfów', 'Andrzej', 'Sapkowski', Category::Fantasy, 1994],
        ['Zbrodnia i kara', 'Fiodor', 'Dostojewski', Category::Crime, 1866],
        ['Morderstwo w Orient Expressie', 'Agatha', 'Christie', Category::Crime, 1934],
        ['Krótka historia czasu', 'Stephen', 'Hawking', Category::Science, 1988],
        ['Kosmos', 'Carl', 'Sagan', Category::Science, 1980],
        ['Sapiens', 'Yuval Noah', 'Harari', Category::History, 2011],
        ['Rok 1984', 'George', 'Orwell', Category::Novel, 1949],
        ['Dzienniki', 'Witold', 'Gombrowicz', Category::Biography, 1957],
        ['Tajny dziennik', 'Sławomir', 'Mrożek', Category::Other, 1990],
        ['Atomowe nawyki', 'James', 'Clear', Category::SelfHelp, 2018],
        ['Mały Książę', 'Antoine', 'de Saint-Exupéry', Category::Children, 1943],
        ['Opowieści z Narnii', 'C. S.', 'Lewis', Category::Children, 1950],
        ['Hobbit', 'J. R. R.', 'Tolkien', Category::Fantasy, 1937],
        ['Medaliony', 'Zofia', 'Nałkowska', Category::NonFiction, 1946],
        ['Inny świat', 'Gustaw', 'Herling-Grudziński', Category::NonFiction, 1951],
        ['Ferdydurke', 'Witold', 'Gombrowicz', Category::Novel, 1937],
    ];

    public function __construct(private readonly UserRepository $users)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $reader = $this->users->findOneByEmail(UiFixtureData::READER_EMAIL) ?? throw new \LogicException('UiUserFixtures must run first.');
        $other = $this->users->findOneByEmail(UiFixtureData::OTHER_EMAIL) ?? throw new \LogicException('UiUserFixtures must run first.');

        $authors = [];
        $tags = [
            'klasyka' => (new Tag())->setName('klasyka')->setSlug('klasyka'),
            'science fiction' => (new Tag())->setName('science fiction')->setSlug('science-fiction'),
        ];
        foreach ($tags as $tag) {
            $manager->persist($tag);
        }

        $books = [];
        foreach (self::BOOKS as $i => [$title, $first, $last, $category, $year]) {
            $key = $first.' '.$last;
            if (!isset($authors[$key])) {
                $authors[$key] = (new Author())->setFirstName($first)->setLastName($last);
                $manager->persist($authors[$key]);
            }
            $book = (new Book())
                ->setTitle($title)
                ->setIsbn(sprintf('97883080%05d', $i + 1))
                ->setPublicationYear($year)
                ->setCategory($category)
                ->setAddedBy(0 === $i % 2 ? $reader : $other)
                ->addAuthor($authors[$key]);
            if ($i < 8) {
                $book->addTag($tags['klasyka']);
            }
            if (\in_array($last, ['Lem', 'Sapkowski'], true)) {
                $book->addTag($tags['science fiction']);
            }
            if ('Solaris' === $title) {
                $book->setDescription("Planeta pokryta oceanem, który myśli.\nOpowieść o granicach poznania.");
            }
            $manager->persist($book);
            $books[$title] = $book;
        }
        $manager->flush();

        // Solaris is read by the demo reader (with a comment) and by the other user (a visible comment on the detail page).
        $reads = [
            [$reader, 'Solaris', 5, 'Klasyka, którą warto czytać wolno.', '2026-10-01'],
            [$other, 'Solaris', 4, 'Świetna, choć trudna.', '2026-10-03'],
            [$reader, 'Lalka', 4, null, '2026-09-12'],
            [$reader, 'Hobbit', 5, 'Powrót do dzieciństwa.', '2026-08-20'],
        ];
        foreach ($reads as [$user, $title, $rating, $comment, $readOn]) {
            $manager->persist(
                (new ReadBook())->setUser($user)->setBook($books[$title])->setRating($rating)->setComment($comment)->setReadOn(new \DateTimeImmutable($readOn)),
            );
        }
        $manager->flush();
    }

    public static function getGroups(): array
    {
        return [UiFixtureData::GROUP_CATALOGUE];
    }

    public function getDependencies(): array
    {
        return [UiUserFixtures::class];
    }
}
