-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Aug 25, 2025 at 06:19 AM
-- Server version: 8.0.41
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rakuel`
--

-- --------------------------------------------------------

--
-- Table structure for table `calendar_eventType`
--

CREATE TABLE `calendar_eventType` (
  `id` int NOT NULL,
  `name` varchar(63) NOT NULL,
  `defaultEventName` varchar(63) NOT NULL,
  `colorId` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `calendar_eventType`
--

INSERT INTO `calendar_eventType` (`id`, `name`, `defaultEventName`, `colorId`) VALUES
(1, 'Lecture', 'Lecture', 1),
(2, 'Fun', 'Fun', 2);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `calendar_eventType`
--
ALTER TABLE `calendar_eventType`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `defaultEventName` (`defaultEventName`),
  ADD KEY `colorId` (`colorId`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `calendar_eventType`
--
ALTER TABLE `calendar_eventType`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `calendar_eventType`
--
ALTER TABLE `calendar_eventType`
  ADD CONSTRAINT `calendar_eventType_ibfk_1` FOREIGN KEY (`colorId`) REFERENCES `calendar_color` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
