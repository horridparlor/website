-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 13, 2025 at 06:03 PM
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
-- Table structure for table `hardest_card`
--

CREATE TABLE `hardest_card` (
  `id` int(11) NOT NULL,
  `name` varchar(31) NOT NULL,
  `cardTypeId` int(11) NOT NULL,
  `keywordId` int(11) DEFAULT NULL,
  `keywordId2` int(11) DEFAULT NULL,
  `keywordId3` int(11) DEFAULT NULL,
  `houseId` int(11) NOT NULL,
  `rarityId` int(11) NOT NULL,
  `releaseVersionId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `hardest_card`
--

INSERT INTO `hardest_card` (`id`, `name`, `cardTypeId`, `keywordId`, `keywordId2`, `keywordId3`, `houseId`, `rarityId`, `releaseVersionId`) VALUES
(1, 'Pebble', 1, NULL, NULL, NULL, 8, 3, 1),
(2, 'Scribble Paper', 2, NULL, NULL, NULL, 8, 3, 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `hardest_card`
--
ALTER TABLE `hardest_card`
  ADD PRIMARY KEY (`id`),
  ADD KEY `keywordId` (`keywordId`),
  ADD KEY `keywordId2` (`keywordId2`),
  ADD KEY `keywordId3` (`keywordId3`),
  ADD KEY `houseId` (`houseId`),
  ADD KEY `rarityId` (`rarityId`),
  ADD KEY `releaseVersionId` (`releaseVersionId`),
  ADD KEY `cardTypeId` (`cardTypeId`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `hardest_card`
--
ALTER TABLE `hardest_card`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `hardest_card`
--
ALTER TABLE `hardest_card`
  ADD CONSTRAINT `hardest_card_ibfk_1` FOREIGN KEY (`keywordId`) REFERENCES `hardest_keyword` (`id`),
  ADD CONSTRAINT `hardest_card_ibfk_2` FOREIGN KEY (`keywordId2`) REFERENCES `hardest_keyword` (`id`),
  ADD CONSTRAINT `hardest_card_ibfk_3` FOREIGN KEY (`keywordId3`) REFERENCES `hardest_keyword` (`id`),
  ADD CONSTRAINT `hardest_card_ibfk_4` FOREIGN KEY (`houseId`) REFERENCES `hardest_house` (`id`),
  ADD CONSTRAINT `hardest_card_ibfk_5` FOREIGN KEY (`rarityId`) REFERENCES `hardest_rarity` (`id`),
  ADD CONSTRAINT `hardest_card_ibfk_6` FOREIGN KEY (`releaseVersionId`) REFERENCES `hardest_releaseVersion` (`id`),
  ADD CONSTRAINT `hardest_card_ibfk_7` FOREIGN KEY (`cardTypeId`) REFERENCES `hardest_cardType` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
