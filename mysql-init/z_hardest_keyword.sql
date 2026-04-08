-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 13, 2025 at 05:24 PM
-- Server version: 10.6.22-MariaDB-cll-lve
-- PHP Version: 8.4.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ctckvshx_rakuel`
--

-- --------------------------------------------------------

--
-- Table structure for table `hardest_keyword`
--

CREATE TABLE `hardest_keyword` (
  `id` int(11) NOT NULL,
  `name` varchar(16) NOT NULL,
  `description` varchar(255) NOT NULL,
  `releaseVersionId` int(11) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `hardest_keyword`
--

INSERT INTO `hardest_keyword` (`id`, `name`, `description`, `releaseVersionId`) VALUES
(1, 'Alpha-werewolf', 'End of turn, werewolfs in hand become the color you played, and gain multiply.', 1),
(2, 'Aura Farming', '[i](Cannot be drawn unless you have played 10 SAME_TYPES in a row.)[/i]', 1),
(3, 'Auto-hydra', 'When drawn, gains 3 random keywords.', 1),
(4, 'Berserk', 'Fights top card of opponent\'s deck. If wins, repeat.', 1),
(5, 'Brotherhood', 'Double multiplier for each brotherhood member played this game.', 1),
(6, 'Buried', 'Played face-down.', 1),
(7, 'Carrot-eater', 'Eats a random keyword from enemy.', 1),
(8, 'Celebrate', 'Discard your hand, then draw 1.', 1),
(9, 'Chameleon', 'Whenever opponent gets a card, changes color.', 1),
(10, 'Champion', 'Games with this card give double points.', 1),
(11, 'Cloning', 'Create a permanent copy of a card in hand.', 1),
(12, 'Contagious', 'A card in hand permanently becomes a SAME_TYPE.', 1),
(13, 'Cooties', 'Defeats any card without an effect.', 1),
(14, 'Copycat', 'Copies opponent\'s card type.', 1),
(15, 'Cursed', 'Cannot be replaced or destroyed.', 1),
(16, 'Devour', 'Eats the first card opponent plays.', 1),
(17, 'Digital', 'Counterspell from hand. [i](Replaces your card.)[/i]', 1),
(18, 'Dirt', 'Give a card in opponent\'s hand a negative multiplier. [i]Multiply by 2 for each card of the same type they have played in a row.[/i]', 1),
(19, 'Divine', 'Defeats any undead.', 1),
(20, 'Electrocute', 'Defeats any ocean card.', 1),
(21, 'EMP', 'Negates digital and bluetooth stamp.', 1),
(22, 'Extra-salty', 'If loses, lose 3 points.', 1),
(23, 'Greed', 'If loses, draw 2.', 1),
(24, 'High-ground', 'Automatically defeats any face-down card.', 1),
(25, 'High-nut', 'Nuts on any face-down card.', 1),
(26, 'Horse-gear', 'Draw a horse.', 1),
(27, 'Hydra', 'Gains 3 random keywords.', 1),
(28, 'Incinerate', 'Permanently destroys defeated cards.', 1),
(29, 'Influencer', 'Opponent\'s top card becomes SAME_BASIC.', 1),
(30, 'Multi-spy', 'Fights up to 3 random cards in opponent\'s hand.', 1),
(31, 'Multiply', 'Double multiplier for each SAME_TYPE played in a row.', 1),
(32, 'Mushy', 'Loses to any rock.', 1),
(33, 'Negative', 'While in hand, increases hand size by 1.', 1),
(34, 'November', 'Defeats any nut.', 1),
(35, 'Nut', 'Point for every turn since your last nut.', 1),
(36, 'Nut Collector', 'Shuffle 3 nuts into your deck.', 1),
(37, 'Nut Stealer', 'If opponent would nut, you nut twice instead.', 1),
(38, 'Ocean', '[i]When wet, scissors gain rust, papers gain mushy.[/i]', 1),
(39, 'Ocean Dweller', 'If stays in hand, gain a point. [i](Also triggers if becomes wet.)[/i]', 1),
(40, 'Pair', 'Wins in a tie.', 1),
(41, 'Pair-breaker', 'Defeats any card with pair or a double stamp.', 1),
(42, 'Perfect Clone', 'Create a perfect copy of a card in hand.', 1),
(43, 'Pick-up', 'End of turn, discard this card from your hand.', 1),
(44, 'Positive', 'Double your points.', 1),
(45, 'Rainbow', 'Each card in opponent\'s hand becomes a random card of same type.', 1),
(46, 'Reload', 'Shuffle a random gun into your deck.', 1),
(47, 'Rust', 'Defeats any gun.', 1),
(48, 'Sabotage', 'Destroy a random card in opponent\'s hand.', 1),
(49, 'Salty', 'If loses, lose a point.', 1),
(50, 'Scammer', '100 points!', 1),
(51, 'Secrets', 'Loses to spies and gives them 3 points.', 1),
(52, 'Shadow-replace', 'Counterspell from top of deck. [i](Replaces your card.)[/i]', 1),
(53, 'Shared Nut', 'Both players nut.', 1),
(54, 'Silver', 'Defeats any werewolf.', 1),
(55, 'Sinful', 'If loses, permanently destroy this card.', 1),
(56, 'Soul Hunter', 'You get the cards this defeats at the start of next game.', 1),
(57, 'Spring Arrives', 'Fill your hand. [i]Double multiplier for each card drawn.[/i]', 1),
(58, 'Spy', 'Fights a random card in opponent\'s hand.', 1),
(59, 'Tidal', 'If wet, turns into a gun.', 1),
(60, 'Time-stop', 'Extends your turn.', 1),
(61, 'Undead', 'Takes 3 SAME_TYPES from your grave and turns into a gun.', 1),
(62, 'Vampire', 'If wins, drains the points from opponent.', 1),
(63, 'Very Nutty', 'Doubles your next nut.', 1),
(64, 'Werewolf', 'End of turn, changes color. [i](In hand.)[/i]', 1),
(65, 'Wrapped', 'Next card you play, gains buried.', 1),
(66, 'Skibbidy', 'Double multiplier for each card in your hand.', 2);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `hardest_keyword`
--
ALTER TABLE `hardest_keyword`
  ADD PRIMARY KEY (`id`),
  ADD KEY `releaseVersionId` (`releaseVersionId`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `hardest_keyword`
--
ALTER TABLE `hardest_keyword`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `hardest_keyword`
--
ALTER TABLE `hardest_keyword`
  ADD CONSTRAINT `hardest_keyword_ibfk_1` FOREIGN KEY (`releaseVersionId`) REFERENCES `hardest_releaseVersion` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
