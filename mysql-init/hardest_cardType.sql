-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 13, 2025 at 05:51 PM
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
-- Table structure for table `hardest_cardType`
--

CREATE TABLE `hardest_cardType` (
  `id` int(11) NOT NULL,
  `name` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `hardest_cardType`
--

INSERT INTO `hardest_cardType` (`id`, `name`) VALUES
(1, 'Rock'),
(2, 'Paper'),
(3, 'Scissor'),
(4, 'Gun'),
(5, 'Mimic'),
(6, 'God'),
(7, 'Bedrock'),
(8, 'Zipper'),
(9, 'Rockstar');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `hardest_cardType`
--
ALTER TABLE `hardest_cardType`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `hardest_cardType`
--
ALTER TABLE `hardest_cardType`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
