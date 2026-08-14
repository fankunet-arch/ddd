-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- 主机： 192.168.2.40:3308
-- 生成日期： 2026-08-14 11:50:21
-- 服务器版本： 5.5.47
-- PHP 版本： 8.2.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `coolroid`
--
DROP DATABASE IF EXISTS `coolroid`;
CREATE DATABASE IF NOT EXISTS `coolroid` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `coolroid`;

-- --------------------------------------------------------

--
-- 表的结构 `allergen`
--

DROP TABLE IF EXISTS `allergen`;
CREATE TABLE `allergen` (
  `allergen_id` int(11) NOT NULL,
  `name1` varchar(50) NOT NULL,
  `name2` varchar(50) NOT NULL,
  `description1` varchar(255) DEFAULT NULL,
  `description2` varchar(255) DEFAULT NULL,
  `icon` varchar(512) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `allergen`
--
DROP TRIGGER IF EXISTS `trigger_allergen_add`;
DELIMITER $$
CREATE TRIGGER `trigger_allergen_add` AFTER INSERT ON `allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_allergen_del`;
DELIMITER $$
CREATE TRIGGER `trigger_allergen_del` AFTER DELETE ON `allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_allergen_update`;
DELIMITER $$
CREATE TRIGGER `trigger_allergen_update` AFTER UPDATE ON `allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `baidu_region`
--

DROP TABLE IF EXISTS `baidu_region`;
CREATE TABLE `baidu_region` (
  `area_id` int(11) NOT NULL DEFAULT '0',
  `name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `bargain_price_item`
--

DROP TABLE IF EXISTS `bargain_price_item`;
CREATE TABLE `bargain_price_item` (
  `bargain_item_id` int(11) NOT NULL,
  `bargain_item_number` int(11) NOT NULL,
  `bargain_item_name` varchar(60) DEFAULT NULL,
  `bargain_status` int(11) DEFAULT NULL,
  `bargain_stype` int(11) DEFAULT NULL,
  `bargain_num_cur` float DEFAULT NULL,
  `bargain_num` float DEFAULT NULL,
  `bargain_price` double(15,2) DEFAULT NULL,
  `bargain_vip` bit(1) DEFAULT NULL,
  `bargain_start_date` date DEFAULT NULL,
  `bargain_end_date` date DEFAULT NULL,
  `bargain_start_time` time DEFAULT NULL,
  `bargain_end_time` time DEFAULT NULL,
  `is_every_day` bit(1) DEFAULT NULL,
  `now_use_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `bargain_price_item`
--
DROP TRIGGER IF EXISTS `trigger_soldout_add`;
DELIMITER $$
CREATE TRIGGER `trigger_soldout_add` AFTER INSERT ON `bargain_price_item` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET soldout = soldout | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_soldout_del`;
DELIMITER $$
CREATE TRIGGER `trigger_soldout_del` AFTER DELETE ON `bargain_price_item` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET soldout = soldout | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_soldout_update`;
DELIMITER $$
CREATE TRIGGER `trigger_soldout_update` AFTER UPDATE ON `bargain_price_item` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET soldout = soldout | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `cashbox_in_out`
--

DROP TABLE IF EXISTS `cashbox_in_out`;
CREATE TABLE `cashbox_in_out` (
  `in_out_id` int(11) NOT NULL,
  `cashbox_id` int(11) DEFAULT NULL,
  `type` int(11) DEFAULT NULL,
  `cash_amount` decimal(11,2) DEFAULT NULL,
  `in_out_time` datetime DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(30) DEFAULT NULL,
  `remark` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `cashbox_period_amout`
--

DROP TABLE IF EXISTS `cashbox_period_amout`;
CREATE TABLE `cashbox_period_amout` (
  `employee_id` int(11) DEFAULT NULL,
  `cashbox_id` int(11) DEFAULT NULL,
  `date_from` datetime DEFAULT NULL,
  `cash_from` float DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `cash_to` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `condiment_groups`
--

DROP TABLE IF EXISTS `condiment_groups`;
CREATE TABLE `condiment_groups` (
  `scomdiment_groups_id` int(11) NOT NULL,
  `comdiment_groups_name` varchar(30) DEFAULT NULL,
  `comdiment_groups_name2` varchar(30) DEFAULT NULL,
  `touchscreen_style` int(11) DEFAULT NULL,
  `price` double(15,2) DEFAULT NULL,
  `combine_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `condiment_groups`
--
DROP TRIGGER IF EXISTS `trigger_condiment_groups_add`;
DELIMITER $$
CREATE TRIGGER `trigger_condiment_groups_add` AFTER INSERT ON `condiment_groups` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_condiment_groups_del`;
DELIMITER $$
CREATE TRIGGER `trigger_condiment_groups_del` AFTER DELETE ON `condiment_groups` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_condiment_groups_update`;
DELIMITER $$
CREATE TRIGGER `trigger_condiment_groups_update` AFTER UPDATE ON `condiment_groups` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `condiment_membership`
--

DROP TABLE IF EXISTS `condiment_membership`;
CREATE TABLE `condiment_membership` (
  `condiment_groups_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `condiment_membership`
--
DROP TRIGGER IF EXISTS `trigger_condiment_membership_add`;
DELIMITER $$
CREATE TRIGGER `trigger_condiment_membership_add` AFTER INSERT ON `condiment_membership` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_condiment_membership_del`;
DELIMITER $$
CREATE TRIGGER `trigger_condiment_membership_del` AFTER DELETE ON `condiment_membership` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_condiment_membership_update`;
DELIMITER $$
CREATE TRIGGER `trigger_condiment_membership_update` AFTER UPDATE ON `condiment_membership` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `consumption_limit`
--

DROP TABLE IF EXISTS `consumption_limit`;
CREATE TABLE `consumption_limit` (
  `consumption_limit_id` int(11) NOT NULL DEFAULT '0',
  `consumption_limit_name` varchar(30) DEFAULT NULL,
  `limit_type` int(11) DEFAULT NULL,
  `limit_amount` double(15,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `consumption_limit`
--
DROP TRIGGER IF EXISTS `trigger_consumption_limit_add`;
DELIMITER $$
CREATE TRIGGER `trigger_consumption_limit_add` AFTER INSERT ON `consumption_limit` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_consumption_limit_del`;
DELIMITER $$
CREATE TRIGGER `trigger_consumption_limit_del` AFTER DELETE ON `consumption_limit` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_consumption_limit_update`;
DELIMITER $$
CREATE TRIGGER `trigger_consumption_limit_update` AFTER UPDATE ON `consumption_limit` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `country`
--

DROP TABLE IF EXISTS `country`;
CREATE TABLE `country` (
  `country_id` int(11) NOT NULL DEFAULT '0',
  `name` varchar(100) DEFAULT NULL,
  `abbr` varchar(100) DEFAULT NULL,
  `code` varchar(20) DEFAULT NULL,
  `ccTLD` varchar(10) DEFAULT NULL,
  `name_cn` varchar(100) DEFAULT NULL,
  `currency_display_name` varchar(100) DEFAULT NULL,
  `currency_display_name_cn` varchar(100) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT NULL,
  `currency_name` varchar(20) DEFAULT NULL,
  `flag` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `coupon_checkout`
--

DROP TABLE IF EXISTS `coupon_checkout`;
CREATE TABLE `coupon_checkout` (
  `id` int(11) UNSIGNED NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `coupon_code` varchar(40) DEFAULT NULL,
  `coupon_name` varchar(200) DEFAULT NULL,
  `coupon_source` varchar(40) DEFAULT NULL,
  `coupon_detail` varchar(200) DEFAULT NULL,
  `coupon_condition` varchar(200) DEFAULT NULL,
  `checkout_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `course`
--

DROP TABLE IF EXISTS `course`;
CREATE TABLE `course` (
  `course_id` int(11) NOT NULL,
  `course_name_1` varchar(40) DEFAULT '',
  `course_name_2` varchar(40) DEFAULT '',
  `course_description` varchar(200) DEFAULT NULL,
  `price` float DEFAULT NULL,
  `icon` varchar(256) DEFAULT NULL,
  `serving _period_class` int(11) DEFAULT NULL,
  `serving _rvc_class` int(11) DEFAULT NULL,
  `print_class` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `course_detail`
--

DROP TABLE IF EXISTS `course_detail`;
CREATE TABLE `course_detail` (
  `detail_id` int(11) NOT NULL,
  `course_group_id` int(11) NOT NULL DEFAULT '0',
  `menu_item_id` int(11) NOT NULL DEFAULT '0',
  `item_course_name` varchar(200) DEFAULT NULL,
  `unit` varchar(100) DEFAULT NULL,
  `num` float DEFAULT '1',
  `price` double(15,2) DEFAULT '0.00',
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `course_detail`
--
DROP TRIGGER IF EXISTS `trigger_course_detail_add`;
DELIMITER $$
CREATE TRIGGER `trigger_course_detail_add` AFTER INSERT ON `course_detail` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_course_detail_del`;
DELIMITER $$
CREATE TRIGGER `trigger_course_detail_del` AFTER DELETE ON `course_detail` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_course_detail_update`;
DELIMITER $$
CREATE TRIGGER `trigger_course_detail_update` AFTER UPDATE ON `course_detail` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `course_group`
--

DROP TABLE IF EXISTS `course_group`;
CREATE TABLE `course_group` (
  `course_group_id` int(11) NOT NULL DEFAULT '0',
  `course_group_name` varchar(40) DEFAULT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `is_must` int(11) DEFAULT NULL,
  `choose_num` int(11) DEFAULT NULL,
  `slu_id` int(11) DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `course_group`
--
DROP TRIGGER IF EXISTS `trigger_course_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_course_group_add` AFTER INSERT ON `course_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_course_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_course_group_del` AFTER DELETE ON `course_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_course_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_course_group_update` AFTER UPDATE ON `course_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `critical_operations`
--

DROP TABLE IF EXISTS `critical_operations`;
CREATE TABLE `critical_operations` (
  `operation_id` int(11) NOT NULL,
  `operation_type` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(30) DEFAULT NULL,
  `operation_time` datetime DEFAULT NULL,
  `operation_name` varchar(30) DEFAULT NULL,
  `operation_detail` varchar(10240) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `critical_operation_type`
--

DROP TABLE IF EXISTS `critical_operation_type`;
CREATE TABLE `critical_operation_type` (
  `operation_type_id` int(11) NOT NULL DEFAULT '0',
  `operation_type_name` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `customer`
--

DROP TABLE IF EXISTS `customer`;
CREATE TABLE `customer` (
  `customer_id` int(11) NOT NULL,
  `card_id` varchar(40) DEFAULT '',
  `customer_name` varchar(40) DEFAULT NULL,
  `customer_class` int(11) DEFAULT '0',
  `times` int(11) DEFAULT '0',
  `amount` decimal(11,2) DEFAULT NULL,
  `telephone_1` varchar(40) DEFAULT NULL,
  `telephone_2` varchar(40) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `postcode` varchar(30) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `is_set_birthday` bit(1) DEFAULT b'0',
  `birthday` date DEFAULT NULL,
  `sex` bit(1) DEFAULT b'1',
  `description` varchar(200) DEFAULT '',
  `score` int(11) DEFAULT '0',
  `is_pre_comsume` int(11) DEFAULT NULL,
  `card_status` int(11) DEFAULT '0',
  `cid` varchar(40) DEFAULT NULL,
  `pwd` varchar(40) DEFAULT NULL,
  `open_date` datetime DEFAULT NULL,
  `address2` varchar(200) NOT NULL DEFAULT '',
  `postcode2` varchar(100) NOT NULL DEFAULT '',
  `address3` varchar(200) NOT NULL DEFAULT '',
  `postcode3` varchar(100) NOT NULL DEFAULT '',
  `required` varchar(200) NOT NULL DEFAULT '',
  `distance` int(11) NOT NULL DEFAULT '0',
  `delivery_fee` double(15,2) DEFAULT '0.00',
  `mapref` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `customer_consume`
--

DROP TABLE IF EXISTS `customer_consume`;
CREATE TABLE `customer_consume` (
  `card_id` varchar(40) NOT NULL,
  `amount` decimal(11,2) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(40) DEFAULT NULL,
  `time` datetime DEFAULT NULL,
  `extend_1` varchar(40) DEFAULT NULL,
  `id` int(10) UNSIGNED NOT NULL,
  `type` int(11) DEFAULT '0',
  `order_head_id` int(11) DEFAULT NULL,
  `web_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `custid` int(11) NOT NULL DEFAULT '0',
  `customer_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `customer_display`
--

DROP TABLE IF EXISTS `customer_display`;
CREATE TABLE `customer_display` (
  `customer_display_id` int(11) NOT NULL DEFAULT '0',
  `customer_display_name` varchar(30) DEFAULT '',
  `com_port` int(11) NOT NULL DEFAULT '0',
  `baud_rate` int(11) DEFAULT NULL,
  `parity_type` int(11) DEFAULT NULL,
  `num_data_bit` int(11) DEFAULT NULL,
  `num_stop_bits` int(11) DEFAULT NULL,
  `type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `customer_display`
--
DROP TRIGGER IF EXISTS `trigger_customer_display_add`;
DELIMITER $$
CREATE TRIGGER `trigger_customer_display_add` AFTER INSERT ON `customer_display` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_customer_display_del`;
DELIMITER $$
CREATE TRIGGER `trigger_customer_display_del` AFTER DELETE ON `customer_display` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_customer_display_update`;
DELIMITER $$
CREATE TRIGGER `trigger_customer_display_update` AFTER UPDATE ON `customer_display` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `customer_language`
--

DROP TABLE IF EXISTS `customer_language`;
CREATE TABLE `customer_language` (
  `lang_slug` varchar(10) DEFAULT NULL,
  `status` int(11) DEFAULT '1',
  `display_name` varchar(30) DEFAULT NULL,
  `modify_time` datetime DEFAULT NULL,
  `app_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `customer_language_trans`
--

DROP TABLE IF EXISTS `customer_language_trans`;
CREATE TABLE `customer_language_trans` (
  `lang_id` varchar(26) DEFAULT NULL,
  `lang_text` varchar(256) DEFAULT NULL,
  `lang_slug` varchar(10) DEFAULT NULL,
  `app_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `customer_order`
--

DROP TABLE IF EXISTS `customer_order`;
CREATE TABLE `customer_order` (
  `card_id` varchar(40) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `saler_id` int(11) DEFAULT NULL,
  `saler_name` varchar(40) DEFAULT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `operator_name` varchar(40) DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `is_approved` int(11) DEFAULT '0',
  `kds_notify` int(11) DEFAULT '0',
  `remark` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `customer_order_notify`
--

DROP TABLE IF EXISTS `customer_order_notify`;
CREATE TABLE `customer_order_notify` (
  `order_head_id` int(11) NOT NULL,
  `phone` varchar(30) DEFAULT '',
  `is_notify` int(11) DEFAULT '0',
  `notify_time` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `dcb_order`
--

DROP TABLE IF EXISTS `dcb_order`;
CREATE TABLE `dcb_order` (
  `order_head_id` int(11) NOT NULL,
  `time_diff` varchar(30) DEFAULT NULL,
  `dcb_id` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `delivery_fee`
--

DROP TABLE IF EXISTS `delivery_fee`;
CREATE TABLE `delivery_fee` (
  `min_dist` double DEFAULT NULL,
  `max_dist` double DEFAULT NULL,
  `fee` double DEFAULT NULL,
  `free_threshold` double DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `delivery_fee`
--
DROP TRIGGER IF EXISTS `trigger_delivery_fee_add`;
DELIMITER $$
CREATE TRIGGER `trigger_delivery_fee_add` AFTER INSERT ON `delivery_fee` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_delivery_fee_del`;
DELIMITER $$
CREATE TRIGGER `trigger_delivery_fee_del` AFTER DELETE ON `delivery_fee` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_delivery_fee_update`;
DELIMITER $$
CREATE TRIGGER `trigger_delivery_fee_update` AFTER UPDATE ON `delivery_fee` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `delivery_info`
--

DROP TABLE IF EXISTS `delivery_info`;
CREATE TABLE `delivery_info` (
  `delivery_info_id` int(11) NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `distribution_id` int(11) DEFAULT NULL,
  `order_type` int(11) DEFAULT NULL,
  `tender_media_id` int(11) DEFAULT NULL,
  `should_amount` decimal(11,2) DEFAULT NULL,
  `delivery_fee` decimal(11,2) DEFAULT NULL,
  `deliveryman_id` int(11) DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `order_state` int(11) DEFAULT NULL,
  `take_time` datetime DEFAULT NULL,
  `delivered_time` datetime DEFAULT NULL,
  `finish_time` datetime DEFAULT NULL,
  `customer_name` varchar(40) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_eat_time` datetime DEFAULT NULL,
  `customer_address` varchar(100) DEFAULT NULL,
  `customer_zipcode` varchar(100) DEFAULT NULL,
  `distribute_employee_id` int(11) DEFAULT NULL,
  `statement_employee_id` int(11) DEFAULT NULL,
  `edit_time` datetime DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `delivery_immediate` int(11) DEFAULT '0',
  `distance` int(11) DEFAULT NULL,
  `mapref` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `delivery_info`
--
DROP TRIGGER IF EXISTS `trigger_delivery_info_add`;
DELIMITER $$
CREATE TRIGGER `trigger_delivery_info_add` BEFORE INSERT ON `delivery_info` FOR EACH ROW BEGIN 
	set NEW.edit_time=now();
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_delivery_info_update`;
DELIMITER $$
CREATE TRIGGER `trigger_delivery_info_update` BEFORE UPDATE ON `delivery_info` FOR EACH ROW BEGIN 
	set NEW.edit_time=now();
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `descriptors_headers`
--

DROP TABLE IF EXISTS `descriptors_headers`;
CREATE TABLE `descriptors_headers` (
  `descriptors_headers_id` int(11) NOT NULL,
  `descriptors_headers_number` int(11) DEFAULT NULL,
  `descriptors_headers_name` varchar(30) DEFAULT NULL,
  `line_1` varchar(100) DEFAULT NULL,
  `pirnt_type_1` int(11) DEFAULT '0',
  `line_2` varchar(100) DEFAULT NULL,
  `pirnt_type_2` int(11) DEFAULT '0',
  `line_3` varchar(100) DEFAULT NULL,
  `pirnt_type_3` int(11) DEFAULT '0',
  `line_4` varchar(100) DEFAULT NULL,
  `pirnt_type_4` int(11) DEFAULT '0',
  `line_5` varchar(100) DEFAULT NULL,
  `pirnt_type_5` int(11) DEFAULT '0',
  `line_6` varchar(100) DEFAULT NULL,
  `pirnt_type_6` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `descriptors_headers`
--
DROP TRIGGER IF EXISTS `trigger_header_update`;
DELIMITER $$
CREATE TRIGGER `trigger_header_update` AFTER UPDATE ON `descriptors_headers` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `descriptors_menu_item_slu`
--

DROP TABLE IF EXISTS `descriptors_menu_item_slu`;
CREATE TABLE `descriptors_menu_item_slu` (
  `dmi_slu_id` int(11) NOT NULL,
  `dmi_slu_number` int(11) DEFAULT NULL,
  `dmi_slu_name` varchar(30) DEFAULT NULL,
  `dmi_slu_name2` varchar(30) DEFAULT NULL,
  `touchscreen_style` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT '-1',
  `print_class` int(11) DEFAULT '-1',
  `allow_condimentint` int(11) DEFAULT '-1',
  `required_condiment` int(11) DEFAULT '-1',
  `item_type` int(11) DEFAULT '0',
  `major_group` int(11) DEFAULT '-1',
  `family_group` int(11) DEFAULT '-1',
  `period_class_id` int(11) DEFAULT '-1',
  `rvc_class_id` int(11) DEFAULT '-1',
  `ticket_class` int(11) DEFAULT '1',
  `tax_group` int(11) DEFAULT '-1',
  `commission_type` int(11) DEFAULT '0',
  `commission_value` float DEFAULT '0',
  `enable_takeout` tinyint(4) DEFAULT '1',
  `enable_eatin` tinyint(4) DEFAULT '1',
  `box_fee` double DEFAULT '0',
  `condiment1` int(11) DEFAULT '-1',
  `condiment2` int(11) DEFAULT '-1',
  `condiment3` int(11) DEFAULT '-1',
  `condiment4` int(11) DEFAULT '-1',
  `condiment5` int(11) DEFAULT '-1',
  `condiment1_radio` tinyint(4) DEFAULT NULL,
  `condiment2_radio` tinyint(4) DEFAULT NULL,
  `condiment3_radio` tinyint(4) DEFAULT NULL,
  `condiment4_radio` tinyint(4) DEFAULT NULL,
  `condiment5_radio` tinyint(4) DEFAULT NULL,
  `icon` varchar(200) DEFAULT NULL,
  `kiosk_hide` bit(1) DEFAULT b'0',
  `kiosk_priority` int(11) DEFAULT '0',
  `condiment1_max` int(11) DEFAULT '0',
  `condiment2_max` int(11) DEFAULT '0',
  `condiment3_max` int(11) DEFAULT '0',
  `condiment4_max` int(11) DEFAULT '0',
  `condiment5_max` int(11) DEFAULT '0',
  `condiment1_min` int(11) DEFAULT '0',
  `condiment2_min` int(11) DEFAULT '0',
  `condiment3_min` int(11) DEFAULT '0',
  `condiment4_min` int(11) DEFAULT '0',
  `condiment5_min` int(11) DEFAULT '0',
  `special_tax_group` int(11) DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `descriptors_menu_item_slu`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_slu_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_slu_add` AFTER INSERT ON `descriptors_menu_item_slu` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_slu_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_slu_del` AFTER DELETE ON `descriptors_menu_item_slu` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_slu_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_slu_update` AFTER UPDATE ON `descriptors_menu_item_slu` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `descriptors_trailers`
--

DROP TABLE IF EXISTS `descriptors_trailers`;
CREATE TABLE `descriptors_trailers` (
  `descriptors_trailers_id` int(11) NOT NULL,
  `descriptors_trailers_number` int(11) DEFAULT NULL,
  `descriptors_trailers_name` varchar(30) DEFAULT NULL,
  `line_1` varchar(100) DEFAULT NULL,
  `pirnt_type_1` int(11) DEFAULT '0',
  `line_2` varchar(100) DEFAULT NULL,
  `pirnt_type_2` int(11) DEFAULT '0',
  `line_3` varchar(100) DEFAULT NULL,
  `pirnt_type_3` int(11) DEFAULT '0',
  `line_4` varchar(100) DEFAULT NULL,
  `pirnt_type_4` int(11) DEFAULT '0',
  `line_5` varchar(100) DEFAULT NULL,
  `pirnt_type_5` int(11) DEFAULT '0',
  `line_6` varchar(100) DEFAULT NULL,
  `pirnt_type_6` int(11) DEFAULT '0',
  `line_7` varchar(100) DEFAULT NULL,
  `pirnt_type_7` int(11) DEFAULT '0',
  `line_8` varchar(100) DEFAULT NULL,
  `pirnt_type_8` int(11) DEFAULT '0',
  `line_9` varchar(100) DEFAULT NULL,
  `pirnt_type_9` int(11) DEFAULT '0',
  `line_10` varchar(100) DEFAULT NULL,
  `pirnt_type_10` int(11) DEFAULT '0',
  `line_11` varchar(100) DEFAULT NULL,
  `pirnt_type_11` int(11) DEFAULT '0',
  `line_12` varchar(100) DEFAULT NULL,
  `pirnt_type_12` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `descriptors_trailers`
--
DROP TRIGGER IF EXISTS `trigger_trailer_update`;
DELIMITER $$
CREATE TRIGGER `trigger_trailer_update` AFTER UPDATE ON `descriptors_trailers` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `device_checkin`
--

DROP TABLE IF EXISTS `device_checkin`;
CREATE TABLE `device_checkin` (
  `device_id` varchar(100) NOT NULL DEFAULT '',
  `last_checkin_time` varchar(10) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `device_info_cctv`
--

DROP TABLE IF EXISTS `device_info_cctv`;
CREATE TABLE `device_info_cctv` (
  `id` int(10) UNSIGNED NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `user` varchar(50) DEFAULT NULL,
  `port` int(11) DEFAULT NULL,
  `pwd` varchar(50) DEFAULT NULL,
  `type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `discount_service`
--

DROP TABLE IF EXISTS `discount_service`;
CREATE TABLE `discount_service` (
  `discount_service_id` int(11) NOT NULL,
  `discount_service_number` int(11) DEFAULT NULL,
  `discount_service_name` varchar(40) DEFAULT NULL,
  `type` int(11) NOT NULL DEFAULT '0',
  `print_class` int(11) DEFAULT NULL,
  `menu_level_class` int(11) DEFAULT NULL,
  `privilege` int(11) DEFAULT NULL,
  `nlu` int(11) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL,
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `category` int(11) DEFAULT NULL,
  `percent` decimal(11,2) DEFAULT NULL,
  `preset` bit(1) DEFAULT NULL,
  `reference_required` bit(1) DEFAULT NULL,
  `auto_sys_chg` bit(1) DEFAULT NULL,
  `seat_filter_exit` bit(1) DEFAULT NULL,
  `assume_entire_amount` bit(1) DEFAULT NULL,
  `not_with_seat` bit(1) DEFAULT NULL,
  `employee_meal` bit(1) DEFAULT b'0',
  `discount_over_threshold` bit(1) DEFAULT NULL,
  `limit_discount` bit(1) DEFAULT NULL,
  `discount_last_item` bit(1) DEFAULT NULL,
  `single_seat` bit(1) DEFAULT NULL,
  `prorated_subtotal` bit(1) DEFAULT NULL,
  `select_discount` bit(1) DEFAULT NULL,
  `period_class_id` int(11) DEFAULT NULL,
  `rvc_class_id` int(11) DEFAULT NULL,
  `display_name` char(60) DEFAULT NULL,
  `not_print` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `discount_service`
--
DROP TRIGGER IF EXISTS `trigger_discount_service_add`;
DELIMITER $$
CREATE TRIGGER `trigger_discount_service_add` AFTER INSERT ON `discount_service` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_discount_service_del`;
DELIMITER $$
CREATE TRIGGER `trigger_discount_service_del` AFTER DELETE ON `discount_service` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_discount_service_update`;
DELIMITER $$
CREATE TRIGGER `trigger_discount_service_update` AFTER UPDATE ON `discount_service` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `divide_detail`
--

DROP TABLE IF EXISTS `divide_detail`;
CREATE TABLE `divide_detail` (
  `divide_id` int(11) NOT NULL,
  `order_head_id` int(11) NOT NULL,
  `order_detail_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `major_group_id` int(11) NOT NULL,
  `divide_amount` decimal(11,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `ecocash_order`
--

DROP TABLE IF EXISTS `ecocash_order`;
CREATE TABLE `ecocash_order` (
  `order_head_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `should_amount` decimal(11,2) NOT NULL,
  `actual_amount` decimal(11,2) NOT NULL,
  `process_amount` decimal(11,2) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `employee_name` varchar(30) NOT NULL,
  `order_time` datetime NOT NULL,
  `error_type` int(11) NOT NULL DEFAULT '0' COMMENT '0:交易成功,找零失败;1:交易失败，退款失败',
  `status` int(11) NOT NULL DEFAULT '0' COMMENT '0: 为平账；1:已平账 ',
  `param` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `employee`
--

DROP TABLE IF EXISTS `employee`;
CREATE TABLE `employee` (
  `employee_id` int(11) NOT NULL,
  `employee_last_name` varchar(40) DEFAULT '',
  `name_prefix` varchar(20) DEFAULT '',
  `first_name` varchar(20) DEFAULT '',
  `middle_name` varchar(20) DEFAULT '',
  `name_suffix` varchar(20) DEFAULT '',
  `previous_last_name` varchar(20) DEFAULT '',
  `ssn_sin` varchar(40) DEFAULT '',
  `password_id` varchar(20) DEFAULT '',
  `pc_aps_id` varchar(20) DEFAULT '',
  `payroll_id` varchar(20) DEFAULT '',
  `employee_class` int(11) DEFAULT NULL,
  `back_office_class` int(11) DEFAULT NULL,
  `revenue_center` int(11) DEFAULT NULL,
  `is_in_traning` bit(1) DEFAULT b'0',
  `is_minor` bit(1) DEFAULT b'0',
  `cash_drawer` int(11) DEFAULT NULL,
  `late_clock` int(11) DEFAULT '0',
  `lds_id` int(11) DEFAULT NULL,
  `cashier` int(11) DEFAULT NULL,
  `language_id` int(11) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `job_rates` int(11) DEFAULT NULL,
  `check_name` varchar(40) DEFAULT '',
  `image` varchar(256) DEFAULT '',
  `front_password` varchar(40) DEFAULT '',
  `back_password` varchar(40) DEFAULT '',
  `period_class_id` int(11) DEFAULT NULL,
  `rvc_class_id` int(11) DEFAULT NULL,
  `commission` float DEFAULT '0',
  `code` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `employee`
--
DROP TRIGGER IF EXISTS `trigger_employee_add`;
DELIMITER $$
CREATE TRIGGER `trigger_employee_add` AFTER INSERT ON `employee` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_employee_del`;
DELIMITER $$
CREATE TRIGGER `trigger_employee_del` AFTER DELETE ON `employee` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_employee_update`;
DELIMITER $$
CREATE TRIGGER `trigger_employee_update` AFTER UPDATE ON `employee` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `employee_class`
--

DROP TABLE IF EXISTS `employee_class`;
CREATE TABLE `employee_class` (
  `employee_class_id` int(11) NOT NULL,
  `employee_class_number` int(11) DEFAULT NULL,
  `employee_class_name` varchar(40) DEFAULT NULL,
  `menu_item_privilege` int(11) DEFAULT NULL,
  `void_item` bit(1) DEFAULT NULL,
  `edit_check` bit(1) DEFAULT NULL,
  `trans_table` bit(1) DEFAULT NULL,
  `payment` int(11) DEFAULT NULL,
  `refund` bit(1) DEFAULT NULL,
  `report_edit` bit(1) DEFAULT NULL,
  `void_preorder` bit(1) DEFAULT b'0',
  `manager_privilege` int(11) DEFAULT NULL,
  `move_check` bit(1) DEFAULT NULL,
  `split_check` bit(1) DEFAULT NULL,
  `send_order` bit(1) DEFAULT NULL,
  `print_prestatement` bit(1) DEFAULT NULL,
  `open_item` bit(1) DEFAULT NULL,
  `reprice` bit(1) DEFAULT NULL,
  `edit_checkname` bit(1) DEFAULT NULL,
  `print_bill` bit(1) DEFAULT NULL,
  `preorder` int(1) DEFAULT '0',
  `open_drawer` int(11) DEFAULT NULL,
  `reprint_check` bit(1) DEFAULT NULL,
  `end_of_day` bit(1) DEFAULT NULL,
  `delete_check` bit(1) DEFAULT NULL,
  `shift` bit(1) DEFAULT NULL,
  `vip_card` bit(1) DEFAULT NULL,
  `report_view` int(11) DEFAULT NULL,
  `menu_item` bit(1) DEFAULT NULL,
  `is_order` bit(1) DEFAULT b'0',
  `edit_customer` bit(1) DEFAULT b'0',
  `change_setting` bit(1) DEFAULT NULL,
  `del_employee_pwd` bit(1) DEFAULT NULL,
  `open_menu_item` bit(1) DEFAULT NULL,
  `open_config` bit(1) DEFAULT NULL,
  `open_employee_info` bit(1) DEFAULT NULL,
  `open_employee_class` bit(1) DEFAULT NULL,
  `set_tips_employee` bit(1) DEFAULT NULL,
  `set_employee_tips` bit(1) DEFAULT NULL,
  `authority_1` bit(1) DEFAULT NULL,
  `authority_2` bit(1) DEFAULT NULL,
  `authority_3` bit(1) DEFAULT NULL,
  `authority_4` bit(1) DEFAULT NULL,
  `authority_5` bit(1) DEFAULT NULL,
  `authority_6` bit(1) DEFAULT NULL,
  `authority_7` bit(1) DEFAULT NULL,
  `authority_8` bit(1) DEFAULT NULL,
  `authority_9` bit(1) DEFAULT NULL,
  `authority_10` bit(1) DEFAULT NULL,
  `authority_11` int(1) DEFAULT NULL,
  `authority_12` int(1) DEFAULT NULL,
  `authority_13` int(1) DEFAULT NULL,
  `authority_14` int(1) DEFAULT NULL,
  `authority_15` int(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `employee_class`
--
DROP TRIGGER IF EXISTS `trigger_employee_class_add`;
DELIMITER $$
CREATE TRIGGER `trigger_employee_class_add` AFTER INSERT ON `employee_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_employee_class_del`;
DELIMITER $$
CREATE TRIGGER `trigger_employee_class_del` AFTER DELETE ON `employee_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_employee_class_update`;
DELIMITER $$
CREATE TRIGGER `trigger_employee_class_update` AFTER UPDATE ON `employee_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `employee_dynamic`
--

DROP TABLE IF EXISTS `employee_dynamic`;
CREATE TABLE `employee_dynamic` (
  `employee_id` int(11) NOT NULL DEFAULT '0',
  `shift_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `evaluation`
--

DROP TABLE IF EXISTS `evaluation`;
CREATE TABLE `evaluation` (
  `evaluation_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `menu_item_name` varchar(40) DEFAULT NULL,
  `evaluation_type` int(11) DEFAULT '0',
  `evaluation_score` float DEFAULT '0',
  `evaluation_content` varchar(300) DEFAULT '',
  `customer_id` varchar(40) DEFAULT '0',
  `customer_name` varchar(40) DEFAULT '',
  `evaluation_time` datetime DEFAULT NULL,
  `extend_1` varchar(40) DEFAULT '',
  `extend_2` varchar(40) DEFAULT '',
  `extend_3` varchar(40) DEFAULT '',
  `extend_4` int(11) DEFAULT NULL,
  `extend_5` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `family_group`
--

DROP TABLE IF EXISTS `family_group`;
CREATE TABLE `family_group` (
  `family_group_id` int(11) NOT NULL DEFAULT '0',
  `family_group_name` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `family_group`
--
DROP TRIGGER IF EXISTS `trigger_family_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_family_group_add` AFTER INSERT ON `family_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET family_group = family_group | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_family_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_family_group_del` AFTER DELETE ON `family_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET family_group = family_group | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_family_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_family_group_update` AFTER UPDATE ON `family_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET family_group = family_group | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `halo_setting_dojopay`
--

DROP TABLE IF EXISTS `halo_setting_dojopay`;
CREATE TABLE `halo_setting_dojopay` (
  `dojo_account` varchar(64) NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `software_house_id` varchar(64) NOT NULL,
  `installer_id` varchar(64) NOT NULL,
  `cloud_url` varchar(255) NOT NULL,
  `terminal_id` varchar(64) NOT NULL,
  `param1` varchar(64) DEFAULT NULL,
  `param2` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `halo_setting_hantepay`
--

DROP TABLE IF EXISTS `halo_setting_hantepay`;
CREATE TABLE `halo_setting_hantepay` (
  `merchant_no` varchar(64) NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `sign_key` varchar(64) NOT NULL,
  `param1` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `halo_setting_princepay`
--

DROP TABLE IF EXISTS `halo_setting_princepay`;
CREATE TABLE `halo_setting_princepay` (
  `merchant_no` varchar(64) NOT NULL,
  `encrypt_key_id` varchar(64) NOT NULL,
  `beneficiary_account` varchar(64) DEFAULT NULL,
  `currency` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_card`
--

DROP TABLE IF EXISTS `history_card`;
CREATE TABLE `history_card` (
  `id` int(11) NOT NULL,
  `old_card_id` varchar(40) DEFAULT NULL,
  `new_card_id` varchar(40) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(60) DEFAULT NULL,
  `time` datetime DEFAULT NULL,
  `extend_1` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_day_end`
--

DROP TABLE IF EXISTS `history_day_end`;
CREATE TABLE `history_day_end` (
  `history_day_end_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `rvc_center_id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `eidt_time` datetime DEFAULT NULL,
  `sales_amount` decimal(11,2) DEFAULT NULL,
  `discount_amount` decimal(11,2) DEFAULT NULL,
  `service_amount` decimal(11,2) DEFAULT NULL,
  `return_amount` decimal(11,2) DEFAULT NULL,
  `should_amount` decimal(11,2) DEFAULT NULL,
  `actual_amount` decimal(11,2) DEFAULT NULL,
  `eatin_amount` decimal(11,2) DEFAULT NULL,
  `out_amount` decimal(11,2) DEFAULT NULL,
  `invoice_amount` decimal(11,2) DEFAULT NULL,
  `tax_amount` decimal(11,2) DEFAULT NULL,
  `customer_num` int(11) DEFAULT NULL,
  `chk_num` int(11) DEFAULT NULL,
  `table_num` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_major_group`
--

DROP TABLE IF EXISTS `history_major_group`;
CREATE TABLE `history_major_group` (
  `history_major_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `rvc_center_id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `major_group_id` int(11) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_messages`
--

DROP TABLE IF EXISTS `history_messages`;
CREATE TABLE `history_messages` (
  `history_message_id` int(11) NOT NULL,
  `tel` varchar(50) DEFAULT NULL,
  `message_content` varchar(1000) DEFAULT NULL,
  `send_time` datetime DEFAULT NULL,
  `actual_time` datetime DEFAULT NULL,
  `type` int(11) DEFAULT '0',
  `status` int(11) DEFAULT '0',
  `user_id` int(11) DEFAULT NULL,
  `sender` int(11) DEFAULT NULL,
  `extend_1` int(11) DEFAULT NULL,
  `extend_2` varchar(300) DEFAULT NULL,
  `extend_3` varchar(300) DEFAULT NULL,
  `extend_4` varchar(300) DEFAULT NULL,
  `extend_5` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_order_detail`
--

DROP TABLE IF EXISTS `history_order_detail`;
CREATE TABLE `history_order_detail` (
  `order_detail_id` int(11) NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT '1',
  `menu_item_id` int(11) DEFAULT '0',
  `menu_item_name` varchar(60) DEFAULT '',
  `product_price` double(15,2) DEFAULT '0.00',
  `is_discount` bit(1) DEFAULT b'0',
  `original_price` double(15,2) DEFAULT NULL,
  `discount_id` int(11) DEFAULT '0',
  `actual_price` double(15,2) DEFAULT '0.00',
  `is_return_item` bit(1) DEFAULT b'0',
  `order_employee_id` int(11) DEFAULT '0',
  `order_employee_name` varchar(30) DEFAULT '',
  `pos_device_id` int(11) DEFAULT '0',
  `pos_name` varchar(30) DEFAULT '',
  `order_time` datetime DEFAULT NULL,
  `return_time` datetime DEFAULT NULL,
  `return_reason` varchar(200) DEFAULT '',
  `unit` varchar(30) DEFAULT '',
  `is_send` bit(1) DEFAULT b'0',
  `condiment_belong_item` int(11) DEFAULT '0',
  `quantity` float DEFAULT '0',
  `eat_type` int(11) DEFAULT '1',
  `auth_id` int(11) DEFAULT NULL,
  `auth_name` varchar(40) DEFAULT '',
  `weight_entry_required` bit(1) DEFAULT NULL,
  `description` varchar(250) DEFAULT NULL,
  `n_service_type` int(11) DEFAULT NULL,
  `not_print` int(11) DEFAULT NULL,
  `seat_num` int(11) DEFAULT NULL,
  `discount_price` double(15,2) DEFAULT NULL,
  `sales_amount` double(15,2) DEFAULT NULL,
  `is_make` int(11) DEFAULT NULL,
  `print_class` int(11) DEFAULT NULL,
  `rush` int(11) DEFAULT '0',
  `ready_qty` double(15,2) DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `the_cook_id` int(11) DEFAULT '0',
  `description2` varchar(200) DEFAULT NULL,
  `is_hitted` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `history_order_detail`
--
DROP TRIGGER IF EXISTS `trigger_history_order_detail_update`;
DELIMITER $$
CREATE TRIGGER `trigger_history_order_detail_update` AFTER UPDATE ON `history_order_detail` FOR EACH ROW BEGIN 
	update history_order_head set status=1 where order_head_id=NEW.order_head_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `history_order_head`
--

DROP TABLE IF EXISTS `history_order_head`;
CREATE TABLE `history_order_head` (
  `serial_id` int(11) UNSIGNED DEFAULT '0',
  `order_head_id` int(11) NOT NULL,
  `check_number` int(11) DEFAULT NULL,
  `rvc_center_id` int(11) DEFAULT NULL,
  `rvc_center_name` varchar(30) DEFAULT NULL,
  `table_id` int(11) DEFAULT NULL,
  `table_name` varchar(30) DEFAULT NULL,
  `check_id` int(11) NOT NULL DEFAULT '0',
  `open_employee_id` int(11) DEFAULT NULL,
  `open_employee_name` varchar(30) DEFAULT NULL,
  `customer_num` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT '0',
  `customer_name` varchar(30) DEFAULT NULL,
  `pos_device_id` int(11) DEFAULT NULL,
  `pos_name` varchar(30) DEFAULT NULL,
  `order_start_time` datetime DEFAULT NULL,
  `order_end_time` datetime DEFAULT NULL,
  `should_amount` decimal(11,2) DEFAULT NULL,
  `return_amount` decimal(11,2) DEFAULT NULL,
  `discount_amount` decimal(11,2) DEFAULT NULL,
  `actual_amount` decimal(11,2) DEFAULT NULL,
  `print_count` int(11) DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  `eat_type` int(11) DEFAULT NULL,
  `check_name` varchar(30) DEFAULT NULL,
  `original_amount` decimal(11,2) DEFAULT '0.00',
  `service_amount` decimal(11,2) DEFAULT '0.00',
  `edit_time` datetime DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `edit_employee_name` varchar(30) DEFAULT NULL,
  `remark` varchar(150) DEFAULT NULL,
  `is_make` int(11) DEFAULT NULL,
  `delivery_info` varchar(255) DEFAULT NULL,
  `kds_show` int(11) DEFAULT '0',
  `kds_time` datetime DEFAULT NULL,
  `tax_amount` decimal(11,2) DEFAULT '0.00',
  `second_tax_amount` decimal(11,2) DEFAULT '0.00',
  `raw_talbe` int(11) DEFAULT '0',
  `tips_amount` decimal(11,2) DEFAULT '0.00',
  `member_discount` decimal(11,2) DEFAULT '0.00',
  `delivery_fee` decimal(11,2) DEFAULT '0.00',
  `kds_time_true` datetime DEFAULT NULL,
  `offline_payment` varchar(30) DEFAULT NULL,
  `invoice_remark` varchar(100) DEFAULT NULL,
  `waiter` int(11) DEFAULT NULL,
  `b_free_tax` bit(1) DEFAULT b'0',
  `source` varchar(255) DEFAULT NULL,
  `source_txid` varchar(255) DEFAULT NULL,
  `source_status` tinyint(4) DEFAULT '0',
  `online_txid` varchar(255) DEFAULT NULL,
  `tips_employee_id` int(11) DEFAULT '0',
  `tips_employee_name` varchar(30) DEFAULT NULL,
  `is_divide` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_payment`
--

DROP TABLE IF EXISTS `history_payment`;
CREATE TABLE `history_payment` (
  `history_payment_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `rvc_center_id` int(11) NOT NULL,
  `period_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `history_time_clock`
--

DROP TABLE IF EXISTS `history_time_clock`;
CREATE TABLE `history_time_clock` (
  `time_clock_id` int(11) UNSIGNED NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `clock_in_time` datetime DEFAULT NULL,
  `clock_out_time` datetime DEFAULT NULL,
  `duration` time DEFAULT NULL,
  `override_in_early` int(11) DEFAULT NULL,
  `override_in_late` int(11) DEFAULT NULL,
  `override_out_early` int(11) DEFAULT NULL,
  `override_out_late` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `information_screens`
--

DROP TABLE IF EXISTS `information_screens`;
CREATE TABLE `information_screens` (
  `info_id` int(11) NOT NULL,
  `info_number` int(11) DEFAULT NULL,
  `info_name` varchar(100) DEFAULT NULL,
  `line1` varchar(100) DEFAULT NULL,
  `line2` varchar(100) DEFAULT NULL,
  `line3` varchar(100) DEFAULT NULL,
  `line4` varchar(100) DEFAULT NULL,
  `line5` varchar(100) DEFAULT NULL,
  `line6` varchar(100) DEFAULT NULL,
  `line7` varchar(100) DEFAULT NULL,
  `line8` varchar(100) DEFAULT NULL,
  `line9` varchar(100) DEFAULT NULL,
  `line10` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `inventory_setting`
--

DROP TABLE IF EXISTS `inventory_setting`;
CREATE TABLE `inventory_setting` (
  `setting_id` int(11) NOT NULL,
  `ip_addr` varchar(60) DEFAULT NULL,
  `port` int(11) DEFAULT '8000',
  `enable` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `invoices`
--

DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(30) DEFAULT NULL,
  `amount` decimal(10,0) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `remark` varchar(30) DEFAULT NULL,
  `edit_time` datetime DEFAULT NULL,
  `pos_device_id` int(11) DEFAULT NULL,
  `rvc_center_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `invoices_japan`
--

DROP TABLE IF EXISTS `invoices_japan`;
CREATE TABLE `invoices_japan` (
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `receipt` varchar(10000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `invoices_spanish`
--

DROP TABLE IF EXISTS `invoices_spanish`;
CREATE TABLE `invoices_spanish` (
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `tax_id_number` varchar(200) DEFAULT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `postal_code` varchar(200) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `series_code` varchar(200) DEFAULT NULL,
  `invoice_number` varchar(255) DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `batuz_qr_value` varchar(200) DEFAULT NULL,
  `tbai_identifier` varchar(200) DEFAULT NULL,
  `amount` double DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `invoices_spanish_setting`
--

DROP TABLE IF EXISTS `invoices_spanish_setting`;
CREATE TABLE `invoices_spanish_setting` (
  `id` int(11) NOT NULL DEFAULT '0',
  `sender_id_card_number` varchar(200) DEFAULT NULL,
  `sender_full_name` varchar(200) DEFAULT NULL,
  `territory` varchar(200) DEFAULT NULL,
  `series_code` varchar(200) DEFAULT NULL,
  `ClientApiKey` varchar(200) DEFAULT NULL,
  `previous_series_code` varchar(200) DEFAULT NULL,
  `previous_invoice_number` varchar(200) DEFAULT NULL,
  `previous_invoice_date` varchar(200) DEFAULT NULL,
  `previous_signature_value` varchar(1000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `item_main_group`
--

DROP TABLE IF EXISTS `item_main_group`;
CREATE TABLE `item_main_group` (
  `main_group_id` int(11) NOT NULL,
  `main_group_name` varchar(30) DEFAULT NULL,
  `main_group_name2` varchar(30) DEFAULT NULL,
  `second_group_id` int(11) NOT NULL,
  `menu_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `item_main_group`
--
DROP TRIGGER IF EXISTS `trigger_item_main_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_item_main_group_add` AFTER INSERT ON `item_main_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_item_main_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_item_main_group_del` AFTER DELETE ON `item_main_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_item_main_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_item_main_group_update` AFTER UPDATE ON `item_main_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `item_unit`
--

DROP TABLE IF EXISTS `item_unit`;
CREATE TABLE `item_unit` (
  `unit_id` int(11) NOT NULL,
  `unit_name` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `kds_call`
--

DROP TABLE IF EXISTS `kds_call`;
CREATE TABLE `kds_call` (
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `check_number` int(11) DEFAULT NULL,
  `table_id` int(11) DEFAULT NULL,
  `table_name` varchar(30) DEFAULT NULL,
  `check_name` varchar(30) DEFAULT NULL,
  `src` int(11) DEFAULT NULL,
  `create_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `kiosk_item_main_group`
--

DROP TABLE IF EXISTS `kiosk_item_main_group`;
CREATE TABLE `kiosk_item_main_group` (
  `main_group_id` int(11) NOT NULL,
  `icon` varchar(200) DEFAULT NULL,
  `kiosk_priority` int(11) DEFAULT '0',
  `kiosk_hide` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `kiosk_setting`
--

DROP TABLE IF EXISTS `kiosk_setting`;
CREATE TABLE `kiosk_setting` (
  `id` int(11) NOT NULL DEFAULT '0',
  `img_cover` varchar(200) DEFAULT NULL,
  `defaultitem_eatin` int(11) DEFAULT '0',
  `defaultitem_takeout` int(11) DEFAULT '0',
  `item_icon_type` int(11) DEFAULT '0',
  `number_type` int(11) DEFAULT '0',
  `vip_sms` int(11) NOT NULL DEFAULT '0',
  `count_down` int(11) NOT NULL DEFAULT '0',
  `eat_type` int(11) NOT NULL DEFAULT '0',
  `input_sendorder_card` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `k_pay_setting`
--

DROP TABLE IF EXISTS `k_pay_setting`;
CREATE TABLE `k_pay_setting` (
  `appID` varchar(33) NOT NULL,
  `appSecret` varchar(65) NOT NULL,
  `payment_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `lian_tuo_fu_key`
--

DROP TABLE IF EXISTS `lian_tuo_fu_key`;
CREATE TABLE `lian_tuo_fu_key` (
  `appId` varchar(100) NOT NULL DEFAULT '',
  `key` varchar(100) DEFAULT NULL,
  `merchantCode` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `macros`
--

DROP TABLE IF EXISTS `macros`;
CREATE TABLE `macros` (
  `macros_id` int(11) NOT NULL DEFAULT '0',
  `macros_name` varchar(40) DEFAULT NULL,
  `macros_value` varchar(200) DEFAULT NULL,
  `remark` varchar(600) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `macros`
--
DROP TRIGGER IF EXISTS `trigger_macros_add`;
DELIMITER $$
CREATE TRIGGER `trigger_macros_add` AFTER INSERT ON `macros` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_macros_update`;
DELIMITER $$
CREATE TRIGGER `trigger_macros_update` AFTER UPDATE ON `macros` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `major_group`
--

DROP TABLE IF EXISTS `major_group`;
CREATE TABLE `major_group` (
  `major_group_id` int(11) NOT NULL DEFAULT '0',
  `major_group_name` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `major_group`
--
DROP TRIGGER IF EXISTS `trigger_major_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_major_group_add` AFTER INSERT ON `major_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_major_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_major_group_del` AFTER DELETE ON `major_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_major_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_major_group_update` AFTER UPDATE ON `major_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `malaysia_city_code`
--

DROP TABLE IF EXISTS `malaysia_city_code`;
CREATE TABLE `malaysia_city_code` (
  `city_code` varchar(3) NOT NULL,
  `city_name` varchar(51) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `malaysia_msic_code`
--

DROP TABLE IF EXISTS `malaysia_msic_code`;
CREATE TABLE `malaysia_msic_code` (
  `msic_code` varchar(10) NOT NULL,
  `description` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item`
--

DROP TABLE IF EXISTS `menu_item`;
CREATE TABLE `menu_item` (
  `item_id` int(11) NOT NULL,
  `item_name1` varchar(60) DEFAULT NULL,
  `item_name2` varchar(60) DEFAULT NULL,
  `icon` varchar(512) DEFAULT NULL,
  `slu_id` int(11) DEFAULT NULL,
  `nlu` varchar(20) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `print_class` int(11) DEFAULT NULL,
  `item_type` int(11) DEFAULT '0',
  `allow_condiment` int(11) DEFAULT NULL,
  `required_condiment` int(11) DEFAULT '0',
  `check_availability` bit(1) DEFAULT NULL,
  `no_access_mgr` bit(1) DEFAULT NULL,
  `major_group` int(11) DEFAULT '-1',
  `family_group` int(11) DEFAULT '-1',
  `price_1` double(15,2) DEFAULT '0.00',
  `cost_1` double(15,2) DEFAULT '0.00',
  `unit_1` varchar(30) DEFAULT '',
  `date_from_1` date DEFAULT NULL,
  `date_to_1` date DEFAULT NULL,
  `surcharge_1` double(15,2) DEFAULT '0.00',
  `tare_weight_1` float DEFAULT '0',
  `price_2` double(15,2) DEFAULT '0.00',
  `cost_2` double(15,2) DEFAULT '0.00',
  `unit_2` varchar(30) DEFAULT '',
  `date_from_2` date DEFAULT NULL,
  `date_to_2` date DEFAULT NULL,
  `surcharge_2` double(15,2) DEFAULT '0.00',
  `tare_weight_2` float DEFAULT '0',
  `price_3` double(15,2) DEFAULT '0.00',
  `cost_3` double(15,2) DEFAULT '0.00',
  `unit_3` varchar(30) DEFAULT '',
  `date_from_3` date DEFAULT NULL,
  `date_to_3` date DEFAULT NULL,
  `surcharge_3` double(15,2) DEFAULT '0.00',
  `tare_weight_3` float DEFAULT '0',
  `price_4` double(15,2) DEFAULT '0.00',
  `cost_4` double(15,2) DEFAULT '0.00',
  `unit_4` varchar(30) DEFAULT '',
  `date_from_4` date DEFAULT NULL,
  `date_to_4` date DEFAULT NULL,
  `surcharge_4` double(15,2) DEFAULT '0.00',
  `tare_weight_4` float DEFAULT '0',
  `price_5` double(15,2) DEFAULT '0.00',
  `cost_5` double(15,2) DEFAULT '0.00',
  `unit_5` varchar(30) DEFAULT '',
  `date_from_5` date DEFAULT NULL,
  `date_to_5` date DEFAULT NULL,
  `surcharge_5` double(15,2) DEFAULT '0.00',
  `tare_weight_5` float DEFAULT NULL,
  `slu_priority` int(11) DEFAULT '0',
  `period_class_id` int(11) DEFAULT '0',
  `rvc_class_id` int(11) DEFAULT '0',
  `commission_type` int(11) DEFAULT '0',
  `commission_value` float DEFAULT '0',
  `ticket_class` int(11) DEFAULT '1',
  `tax_group` int(11) DEFAULT '-1',
  `is_time_price` tinyint(4) DEFAULT '0',
  `price_per_minute` int(11) DEFAULT '60',
  `takeout_price_1` double(15,2) DEFAULT '0.00',
  `takeout_price_2` double(15,2) DEFAULT '0.00',
  `takeout_price_3` double(15,2) DEFAULT '0.00',
  `takeout_price_4` double(15,2) DEFAULT '0.00',
  `takeout_price_5` double(15,2) DEFAULT '0.00',
  `disc_price_2` double(15,2) DEFAULT '-1.00',
  `disc_price_3` double(15,2) DEFAULT '-1.00',
  `disc_price_4` double(15,2) DEFAULT '-1.00',
  `disc_price_5` double(15,2) DEFAULT '-1.00',
  `sjcode` varchar(20) DEFAULT NULL,
  `sjcode_1` varchar(20) DEFAULT NULL,
  `sjcode_2` varchar(20) DEFAULT NULL,
  `sjcode_3` varchar(20) DEFAULT NULL,
  `sjcode_4` varchar(20) DEFAULT NULL,
  `sjcode_5` varchar(20) DEFAULT NULL,
  `is_divideable` tinyint(4) DEFAULT '0',
  `weight_unit_id` int(11) DEFAULT '1',
  `special_tax_group` int(11) DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_add` AFTER INSERT ON `menu_item` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_del` AFTER DELETE ON `menu_item` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_update` AFTER UPDATE ON `menu_item` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_allergen`
--

DROP TABLE IF EXISTS `menu_item_allergen`;
CREATE TABLE `menu_item_allergen` (
  `item_id` int(11) NOT NULL,
  `allergen_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_allergen`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_allergen_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_allergen_add` AFTER INSERT ON `menu_item_allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_allergen_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_allergen_del` AFTER DELETE ON `menu_item_allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_allergen_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_allergen_update` AFTER UPDATE ON `menu_item_allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_class`
--

DROP TABLE IF EXISTS `menu_item_class`;
CREATE TABLE `menu_item_class` (
  `item_class_id` int(11) NOT NULL,
  `item_class_number` int(11) DEFAULT NULL,
  `item_class_name` varchar(40) DEFAULT NULL,
  `sale_itemizer` int(11) DEFAULT NULL,
  `discount_itemizer` int(11) DEFAULT NULL,
  `print_group` int(11) DEFAULT '-1',
  `halo` int(11) DEFAULT NULL,
  `service_itemizer` int(11) DEFAULT NULL,
  `privilege` int(11) DEFAULT NULL,
  `appetizers` bit(1) DEFAULT NULL,
  `reference_required` bit(1) DEFAULT NULL,
  `beverages` bit(1) DEFAULT NULL,
  `weight_entry_required` bit(1) DEFAULT b'0',
  `condiments` bit(1) DEFAULT NULL,
  `increment_seat_number` bit(1) DEFAULT NULL,
  `condiment_seat_number` bit(1) DEFAULT NULL,
  `condiments_prefixes` bit(1) DEFAULT NULL,
  `match_condiments_quantity` bit(1) DEFAULT NULL,
  `shareable` bit(1) DEFAULT NULL,
  `count_menu_item` bit(1) DEFAULT NULL,
  `lds_items` bit(1) DEFAULT NULL,
  `retail_items` bit(1) DEFAULT NULL,
  `include_in_repeat_round` bit(1) DEFAULT NULL COMMENT ' '
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_divide_setting`
--

DROP TABLE IF EXISTS `menu_item_divide_setting`;
CREATE TABLE `menu_item_divide_setting` (
  `setting_id` int(11) NOT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `major_group_id` int(11) DEFAULT NULL,
  `divide_amount` decimal(11,2) DEFAULT NULL,
  `status` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_divide_setting`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_divide_setting_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_divide_setting_add` AFTER INSERT ON `menu_item_divide_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_divide_setting_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_divide_setting_del` AFTER DELETE ON `menu_item_divide_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_divide_setting_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_divide_setting_update` AFTER UPDATE ON `menu_item_divide_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_hit`
--

DROP TABLE IF EXISTS `menu_item_hit`;
CREATE TABLE `menu_item_hit` (
  `hit_id` int(11) NOT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `hit_item_id` int(11) DEFAULT NULL,
  `week` int(11) DEFAULT NULL,
  `from_time` time DEFAULT NULL,
  `to_time` time DEFAULT NULL,
  `place_class` int(11) DEFAULT NULL,
  `eat_type` int(11) DEFAULT NULL,
  `price` double DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_hit`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_hit_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_hit_add` AFTER INSERT ON `menu_item_hit` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 256;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_hit_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_hit_del` AFTER DELETE ON `menu_item_hit` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 256;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_hit_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_hit_update` AFTER UPDATE ON `menu_item_hit` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 256;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_hit_price`
--

DROP TABLE IF EXISTS `menu_item_hit_price`;
CREATE TABLE `menu_item_hit_price` (
  `menu_item_id` int(11) NOT NULL,
  `is_main` tinyint(1) NOT NULL DEFAULT '0',
  `is_sub` tinyint(1) NOT NULL DEFAULT '0',
  `choose_price` int(11) DEFAULT '-1',
  `hit_price_1` double DEFAULT '-1',
  `hit_price_2` double DEFAULT '-1',
  `hit_price_3` double DEFAULT '-1',
  `hit_price_4` double DEFAULT '-1',
  `hit_price_5` double DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_hit_price`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_hit_price_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_hit_price_add` AFTER INSERT ON `menu_item_hit_price` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 256;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_hit_price_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_hit_price_del` AFTER DELETE ON `menu_item_hit_price` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 256;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_hit_price_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_hit_price_update` AFTER UPDATE ON `menu_item_hit_price` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 256;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_multiple_link`
--

DROP TABLE IF EXISTS `menu_item_multiple_link`;
CREATE TABLE `menu_item_multiple_link` (
  `menu_id` int(11) NOT NULL,
  `item_id_adult` int(11) DEFAULT NULL,
  `item_id_child` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_multiple_link`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_multiple_link_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_multiple_link_add` AFTER INSERT ON `menu_item_multiple_link` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_multiple_link_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_multiple_link_del` AFTER DELETE ON `menu_item_multiple_link` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_multiple_link_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_multiple_link_update` AFTER UPDATE ON `menu_item_multiple_link` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_multiple_setting`
--

DROP TABLE IF EXISTS `menu_item_multiple_setting`;
CREATE TABLE `menu_item_multiple_setting` (
  `menu_id` int(11) NOT NULL,
  `menu_name1` varchar(60) DEFAULT NULL,
  `menu_name2` varchar(60) DEFAULT NULL,
  `menu_mode` int(11) DEFAULT NULL,
  `effect_time` varchar(60) DEFAULT NULL,
  `effect_week` int(11) DEFAULT NULL,
  `effect_date` varchar(255) DEFAULT NULL,
  `icon` varchar(128) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `description2` varchar(255) DEFAULT NULL,
  `is_enable` int(11) NOT NULL DEFAULT '1' COMMENT '启用状态'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_multiple_setting`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_multiple_setting_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_multiple_setting_add` AFTER INSERT ON `menu_item_multiple_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_multiple_setting_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_multiple_setting_del` AFTER DELETE ON `menu_item_multiple_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_multiple_setting_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_multiple_setting_update` AFTER UPDATE ON `menu_item_multiple_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_pad_tag`
--

DROP TABLE IF EXISTS `menu_item_pad_tag`;
CREATE TABLE `menu_item_pad_tag` (
  `tag_id` int(11) NOT NULL DEFAULT '0',
  `item_id` int(11) NOT NULL DEFAULT '0',
  `index` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_pad_tag`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_pad_tag_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_pad_tag_add` AFTER INSERT ON `menu_item_pad_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_pad_tag_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_pad_tag_del` AFTER DELETE ON `menu_item_pad_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_pad_tag_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_pad_tag_update` AFTER UPDATE ON `menu_item_pad_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_slu_allergen`
--

DROP TABLE IF EXISTS `menu_item_slu_allergen`;
CREATE TABLE `menu_item_slu_allergen` (
  `dmi_slu_id` int(11) NOT NULL,
  `allergen_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_slu_allergen`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_slu_allergen_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_slu_allergen_add` AFTER INSERT ON `menu_item_slu_allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_slu_allergen_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_slu_allergen_del` AFTER DELETE ON `menu_item_slu_allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_slu_allergen_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_slu_allergen_update` AFTER UPDATE ON `menu_item_slu_allergen` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 512;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_takeout`
--

DROP TABLE IF EXISTS `menu_item_takeout`;
CREATE TABLE `menu_item_takeout` (
  `takeout_id` int(11) NOT NULL DEFAULT '0',
  `item_id` int(11) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `enable_takeout` tinyint(4) DEFAULT '1',
  `enable_eatin` tinyint(4) DEFAULT '1',
  `enable_sales` tinyint(4) DEFAULT '0',
  `box_fee` double DEFAULT NULL,
  `takeout_index` int(11) DEFAULT '100',
  `img_url` varchar(200) DEFAULT NULL,
  `img_path` varchar(200) DEFAULT NULL,
  `img_url2` varchar(200) DEFAULT NULL,
  `img_path2` varchar(200) DEFAULT NULL,
  `img_url3` varchar(200) DEFAULT NULL,
  `img_path3` varchar(200) DEFAULT NULL,
  `img_url4` varchar(200) DEFAULT NULL,
  `img_path4` varchar(200) DEFAULT NULL,
  `condiment1` int(11) DEFAULT '-1',
  `condiment2` int(11) DEFAULT '-1',
  `condiment3` int(11) DEFAULT '-1',
  `condiment4` int(11) DEFAULT '-1',
  `condiment5` int(11) DEFAULT '-1',
  `condiment1_radio` tinyint(4) DEFAULT NULL,
  `condiment2_radio` tinyint(4) DEFAULT NULL,
  `condiment3_radio` tinyint(4) DEFAULT NULL,
  `condiment4_radio` tinyint(4) DEFAULT NULL,
  `condiment5_radio` tinyint(4) DEFAULT NULL,
  `condiment1_max` int(11) DEFAULT '0',
  `condiment2_max` int(11) DEFAULT '0',
  `condiment3_max` int(11) DEFAULT '0',
  `condiment4_max` int(11) DEFAULT '0',
  `condiment5_max` int(11) DEFAULT '0',
  `condiment1_min` int(11) DEFAULT '0',
  `condiment2_min` int(11) DEFAULT '0',
  `condiment3_min` int(11) DEFAULT '0',
  `condiment4_min` int(11) DEFAULT '0',
  `condiment5_min` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_takeout`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_takeout_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeout_add` AFTER INSERT ON `menu_item_takeout` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_takeout_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeout_del` AFTER DELETE ON `menu_item_takeout` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_takeout_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeout_update` AFTER UPDATE ON `menu_item_takeout` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_takeoutplatform`
--

DROP TABLE IF EXISTS `menu_item_takeoutplatform`;
CREATE TABLE `menu_item_takeoutplatform` (
  `tp_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `plat_site` varchar(100) DEFAULT NULL,
  `price_1` double(15,2) DEFAULT '-1.00',
  `price_2` double(15,2) DEFAULT '-1.00',
  `price_3` double(15,2) DEFAULT '-1.00',
  `price_4` double(15,2) DEFAULT '-1.00',
  `price_5` double(15,2) DEFAULT '-1.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_takeoutplatform`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_takeoutplatform_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeoutplatform_add` AFTER INSERT ON `menu_item_takeoutplatform` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_takeoutplatform_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeoutplatform_del` AFTER DELETE ON `menu_item_takeoutplatform` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_takeoutplatform_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeoutplatform_update` AFTER UPDATE ON `menu_item_takeoutplatform` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `menu_item_takeout_tag`
--

DROP TABLE IF EXISTS `menu_item_takeout_tag`;
CREATE TABLE `menu_item_takeout_tag` (
  `tag_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `status` tinyint(4) DEFAULT '1',
  `index` int(11) DEFAULT '100'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `menu_item_takeout_tag`
--
DROP TRIGGER IF EXISTS `trigger_menu_item_takeout_tag_add`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeout_tag_add` AFTER INSERT ON `menu_item_takeout_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_takeout_tag_del`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeout_tag_del` AFTER DELETE ON `menu_item_takeout_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_menu_item_takeout_tag_update`;
DELIMITER $$
CREATE TRIGGER `trigger_menu_item_takeout_tag_update` AFTER UPDATE ON `menu_item_takeout_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `messages`
--

DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
  `message_id` int(11) NOT NULL,
  `tel` varchar(50) DEFAULT NULL,
  `message_content` varchar(1000) DEFAULT NULL,
  `send_time` datetime DEFAULT NULL,
  `type` int(11) DEFAULT '0',
  `user_id` int(11) DEFAULT '-1',
  `sender` int(11) DEFAULT '-1',
  `extend_1` int(11) DEFAULT '0',
  `extend_2` varchar(300) DEFAULT NULL,
  `extend_3` varchar(300) DEFAULT NULL,
  `extend_4` varchar(300) DEFAULT NULL,
  `extend_5` varchar(300) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `messages_template_item`
--

DROP TABLE IF EXISTS `messages_template_item`;
CREATE TABLE `messages_template_item` (
  `message_tempitem_id` int(11) NOT NULL DEFAULT '0',
  `message_tempitem_name` varchar(50) DEFAULT '',
  `message_tempitem_content` varchar(50) DEFAULT '',
  `column_value` varchar(100) DEFAULT '',
  `type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `miti`
--

DROP TABLE IF EXISTS `miti`;
CREATE TABLE `miti` (
  `order_employee_id` int(11) DEFAULT NULL,
  `order_employee_name` varchar(50) DEFAULT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `menu_item_name` varchar(60) DEFAULT NULL,
  `actual_price` double(15,2) DEFAULT NULL,
  `quantity` float DEFAULT NULL,
  `commission_value` float DEFAULT NULL,
  `commission_type` int(11) DEFAULT NULL
) ENGINE=MEMORY DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `msg_setting`
--

DROP TABLE IF EXISTS `msg_setting`;
CREATE TABLE `msg_setting` (
  `setting_id` int(11) NOT NULL DEFAULT '0',
  `type` int(11) DEFAULT '0',
  `com_port` int(11) DEFAULT '1',
  `ip_address` varchar(50) DEFAULT NULL,
  `ip_port` int(11) DEFAULT NULL,
  `user_id` varchar(50) DEFAULT NULL,
  `user_pwd` varchar(50) DEFAULT NULL,
  `net_server` varchar(500) DEFAULT NULL,
  `net_user` varchar(50) DEFAULT NULL,
  `net_user_pwd` varchar(50) DEFAULT NULL,
  `cr_ip` varchar(50) DEFAULT '',
  `cr_url` varchar(100) DEFAULT '',
  `cr_port` int(11) DEFAULT '2001',
  `cr_user` varchar(50) DEFAULT '',
  `cr_user_pwd` varchar(50) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `muggle_keys`
--

DROP TABLE IF EXISTS `muggle_keys`;
CREATE TABLE `muggle_keys` (
  `muggle_id` int(11) NOT NULL,
  `merchant_id` int(11) DEFAULT NULL,
  `merchant_sn` varchar(255) DEFAULT NULL,
  `merchant_key` varchar(255) DEFAULT NULL,
  `pay_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `online_ordering_paytype`
--

DROP TABLE IF EXISTS `online_ordering_paytype`;
CREATE TABLE `online_ordering_paytype` (
  `paytype_id` int(11) DEFAULT NULL,
  `paytype_name` varchar(100) DEFAULT NULL,
  `paytype_arg1` varchar(100) DEFAULT NULL,
  `paytype_arg2` varchar(100) DEFAULT NULL,
  `paytype_arg3` varchar(100) DEFAULT NULL,
  `paytype_arg4` varchar(100) DEFAULT NULL,
  `paytype_arg5` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `online_ordering_paytype`
--
DROP TRIGGER IF EXISTS `trigger_online_ordering_paytype_add`;
DELIMITER $$
CREATE TRIGGER `trigger_online_ordering_paytype_add` AFTER INSERT ON `online_ordering_paytype` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_online_ordering_paytype_del`;
DELIMITER $$
CREATE TRIGGER `trigger_online_ordering_paytype_del` AFTER DELETE ON `online_ordering_paytype` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_online_ordering_paytype_update`;
DELIMITER $$
CREATE TRIGGER `trigger_online_ordering_paytype_update` AFTER UPDATE ON `online_ordering_paytype` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `online_ordering_paytype_status`
--

DROP TABLE IF EXISTS `online_ordering_paytype_status`;
CREATE TABLE `online_ordering_paytype_status` (
  `paytype_id` int(11) DEFAULT NULL,
  `eat_type` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `online_ordering_paytype_status`
--
DROP TRIGGER IF EXISTS `trigger_online_ordering_paytype_status_add`;
DELIMITER $$
CREATE TRIGGER `trigger_online_ordering_paytype_status_add` AFTER UPDATE ON `online_ordering_paytype_status` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_online_ordering_paytype_status_del`;
DELIMITER $$
CREATE TRIGGER `trigger_online_ordering_paytype_status_del` AFTER DELETE ON `online_ordering_paytype_status` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_online_ordering_paytype_status_update`;
DELIMITER $$
CREATE TRIGGER `trigger_online_ordering_paytype_status_update` AFTER INSERT ON `online_ordering_paytype_status` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `online_setting`
--

DROP TABLE IF EXISTS `online_setting`;
CREATE TABLE `online_setting` (
  `id` int(11) NOT NULL,
  `eat_type` int(11) DEFAULT '0',
  `is_paid` int(11) DEFAULT '0',
  `is_send` int(11) DEFAULT '0',
  `is_close` int(11) DEFAULT '0',
  `add_cod` int(11) DEFAULT '0',
  `payment` int(11) DEFAULT '0',
  `delivery` int(11) DEFAULT '0',
  `apt_print_coutdown` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `operations_cctv`
--

DROP TABLE IF EXISTS `operations_cctv`;
CREATE TABLE `operations_cctv` (
  `operation_id` int(10) UNSIGNED NOT NULL,
  `operation_type` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(50) DEFAULT NULL,
  `order_head_id` varchar(50) DEFAULT NULL,
  `is_cancel` tinyint(1) DEFAULT '0',
  `is_ignore` tinyint(1) DEFAULT '0',
  `amount` double DEFAULT NULL,
  `operation_detail` text,
  `start_time` datetime DEFAULT NULL,
  `record_start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `record_end_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `operation_types_cctv`
--

DROP TABLE IF EXISTS `operation_types_cctv`;
CREATE TABLE `operation_types_cctv` (
  `operation_type_id` int(10) UNSIGNED NOT NULL,
  `operation_type_name` varchar(50) DEFAULT NULL,
  `is_warning` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `order_default_group`
--

DROP TABLE IF EXISTS `order_default_group`;
CREATE TABLE `order_default_group` (
  `order_default_groupid` int(11) NOT NULL,
  `order_default_groupname` varchar(100) DEFAULT NULL,
  `serving_period_class` int(11) DEFAULT '-1',
  `serving_place_class` int(11) DEFAULT '-1',
  `extend_1` int(11) DEFAULT NULL,
  `extend_2` int(11) DEFAULT NULL,
  `extend_3` varchar(300) DEFAULT NULL,
  `extend_4` varchar(300) DEFAULT NULL,
  `extend_5` float DEFAULT NULL,
  `enable_online_ordering` tinyint(4) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `order_default_group`
--
DROP TRIGGER IF EXISTS `trigger_order_default_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_order_default_group_add` AFTER INSERT ON `order_default_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_order_default_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_order_default_group_del` AFTER DELETE ON `order_default_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_order_default_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_order_default_group_update` AFTER UPDATE ON `order_default_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `order_detail`
--

DROP TABLE IF EXISTS `order_detail`;
CREATE TABLE `order_detail` (
  `order_detail_id` int(11) UNSIGNED NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT '1',
  `menu_item_id` int(11) DEFAULT NULL,
  `menu_item_name` varchar(60) DEFAULT NULL,
  `product_price` double(15,2) DEFAULT NULL,
  `is_discount` bit(1) DEFAULT NULL,
  `original_price` double(15,2) DEFAULT NULL,
  `discount_id` int(11) DEFAULT NULL,
  `actual_price` double(15,2) DEFAULT NULL,
  `is_return_item` bit(1) DEFAULT b'0',
  `order_employee_id` int(11) DEFAULT NULL,
  `order_employee_name` varchar(40) DEFAULT NULL,
  `pos_device_id` int(11) DEFAULT NULL,
  `pos_name` varchar(30) DEFAULT NULL,
  `order_time` datetime DEFAULT NULL,
  `return_time` datetime DEFAULT NULL,
  `return_reason` varchar(200) DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `is_send` bit(1) DEFAULT NULL,
  `condiment_belong_item` int(11) DEFAULT NULL,
  `quantity` float DEFAULT '1',
  `eat_type` int(11) DEFAULT '0',
  `auth_id` int(11) DEFAULT NULL,
  `auth_name` varchar(40) DEFAULT '',
  `weight_entry_required` bit(1) DEFAULT NULL,
  `description` varchar(250) DEFAULT NULL,
  `n_service_type` int(11) DEFAULT NULL,
  `not_print` int(11) DEFAULT NULL,
  `seat_num` int(11) DEFAULT NULL,
  `discount_price` double(15,2) DEFAULT NULL,
  `sales_amount` double(15,2) DEFAULT NULL,
  `is_make` int(11) DEFAULT NULL,
  `print_class` int(11) DEFAULT NULL,
  `rush` int(11) DEFAULT '0',
  `ready_qty` double(15,2) DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `the_cook_id` int(11) DEFAULT '0',
  `description2` varchar(200) DEFAULT NULL,
  `is_hitted` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `order_detail`
--
DROP TRIGGER IF EXISTS `trigger_order_detail_add`;
DELIMITER $$
CREATE TRIGGER `trigger_order_detail_add` BEFORE INSERT ON `order_detail` FOR EACH ROW BEGIN 
if NEW.order_detail_id IS NULL then
	set NEW.order_time=now();
end if;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `order_detail_default`
--

DROP TABLE IF EXISTS `order_detail_default`;
CREATE TABLE `order_detail_default` (
  `order_detail_default_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `quantity` float DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `discount_service_id` int(11) DEFAULT NULL,
  `price` double(15,2) DEFAULT NULL,
  `mark` varchar(300) DEFAULT NULL,
  `is_cus_num` int(11) DEFAULT '0',
  `extend_1` int(11) DEFAULT '0',
  `extend_2` int(11) DEFAULT '0',
  `extend_3` varchar(300) DEFAULT NULL,
  `extend_4` varchar(300) DEFAULT NULL,
  `extend_5` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `order_detail_default`
--
DROP TRIGGER IF EXISTS `trigger_order_detail_default_add`;
DELIMITER $$
CREATE TRIGGER `trigger_order_detail_default_add` AFTER INSERT ON `order_detail_default` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_order_detail_default_del`;
DELIMITER $$
CREATE TRIGGER `trigger_order_detail_default_del` AFTER DELETE ON `order_detail_default` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_order_detail_default_update`;
DELIMITER $$
CREATE TRIGGER `trigger_order_detail_default_update` AFTER UPDATE ON `order_detail_default` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `order_head`
--

DROP TABLE IF EXISTS `order_head`;
CREATE TABLE `order_head` (
  `serial_id` int(11) UNSIGNED DEFAULT '0',
  `order_head_id` int(11) UNSIGNED NOT NULL,
  `check_number` int(11) DEFAULT NULL,
  `rvc_center_id` int(11) DEFAULT NULL,
  `rvc_center_name` varchar(30) DEFAULT NULL,
  `table_id` int(11) DEFAULT NULL,
  `table_name` varchar(30) DEFAULT NULL,
  `check_id` int(11) NOT NULL DEFAULT '0',
  `open_employee_id` int(11) DEFAULT NULL,
  `open_employee_name` varchar(30) DEFAULT NULL,
  `customer_num` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT '0',
  `customer_name` varchar(30) DEFAULT NULL,
  `pos_device_id` int(11) DEFAULT NULL,
  `pos_name` varchar(30) DEFAULT NULL,
  `order_start_time` datetime DEFAULT NULL,
  `order_end_time` datetime DEFAULT NULL,
  `should_amount` decimal(11,2) DEFAULT '0.00',
  `return_amount` decimal(11,2) DEFAULT '0.00',
  `discount_amount` decimal(11,2) DEFAULT '0.00',
  `actual_amount` decimal(11,2) DEFAULT '0.00',
  `print_count` int(11) DEFAULT '0',
  `status` int(11) DEFAULT '0',
  `eat_type` int(11) DEFAULT '0',
  `check_name` varchar(30) DEFAULT NULL,
  `original_amount` decimal(11,2) DEFAULT '0.00',
  `service_amount` decimal(11,2) DEFAULT '0.00',
  `edit_time` datetime DEFAULT NULL,
  `party_id` int(11) DEFAULT NULL,
  `edit_employee_name` varchar(30) DEFAULT NULL,
  `remark` varchar(150) DEFAULT NULL,
  `is_make` int(11) DEFAULT NULL,
  `delivery_info` varchar(255) DEFAULT NULL,
  `kds_show` int(11) DEFAULT '0',
  `kds_time` datetime DEFAULT NULL,
  `tax_amount` decimal(11,2) DEFAULT '0.00',
  `second_tax_amount` decimal(11,2) DEFAULT '0.00',
  `raw_talbe` int(11) DEFAULT '0',
  `tips_amount` decimal(11,2) DEFAULT '0.00',
  `member_discount` decimal(11,2) DEFAULT '0.00',
  `delivery_fee` decimal(11,2) DEFAULT '0.00',
  `kds_time_true` datetime DEFAULT NULL,
  `offline_payment` varchar(30) DEFAULT NULL,
  `invoice_remark` varchar(100) DEFAULT NULL,
  `waiter` int(11) DEFAULT NULL,
  `b_free_tax` bit(1) DEFAULT b'0',
  `source` varchar(255) DEFAULT NULL,
  `source_txid` varchar(255) DEFAULT NULL,
  `source_status` tinyint(4) DEFAULT '0',
  `online_txid` varchar(255) DEFAULT NULL,
  `tips_employee_id` int(11) DEFAULT '0',
  `tips_employee_name` varchar(30) DEFAULT NULL,
  `is_divide` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `order_info_cctv`
--

DROP TABLE IF EXISTS `order_info_cctv`;
CREATE TABLE `order_info_cctv` (
  `order_head_id` varchar(50) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `employee_name` varchar(50) DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `record_start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `record_end_time` datetime DEFAULT NULL,
  `item_name` varchar(200) DEFAULT NULL,
  `item_price` varchar(200) DEFAULT NULL,
  `total_amount` double DEFAULT NULL,
  `discount` double DEFAULT NULL,
  `tax` double DEFAULT NULL,
  `should_amount` double DEFAULT NULL,
  `pay_type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `order_types`
--

DROP TABLE IF EXISTS `order_types`;
CREATE TABLE `order_types` (
  `order_type_id` int(11) NOT NULL,
  `order_type_number` int(11) DEFAULT NULL,
  `order_type_name` varchar(30) DEFAULT NULL,
  `is_enable` bit(1) DEFAULT NULL,
  `print_check_receipt` bit(1) DEFAULT NULL,
  `print_order_chit` bit(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `pad_tag`
--

DROP TABLE IF EXISTS `pad_tag`;
CREATE TABLE `pad_tag` (
  `tag_id` int(11) NOT NULL DEFAULT '0',
  `menu_id` int(11) NOT NULL,
  `tag_name` varchar(100) DEFAULT NULL,
  `tag_name2` varchar(100) DEFAULT NULL,
  `index` int(11) DEFAULT '100',
  `status` tinyint(4) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `pad_tag`
--
DROP TRIGGER IF EXISTS `trigger_pad_tag_add`;
DELIMITER $$
CREATE TRIGGER `trigger_pad_tag_add` AFTER INSERT ON `pad_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_pad_tag_del`;
DELIMITER $$
CREATE TRIGGER `trigger_pad_tag_del` AFTER DELETE ON `pad_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 32;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_pad_tag_update`;
DELIMITER $$
CREATE TRIGGER `trigger_pad_tag_update` AFTER UPDATE ON `pad_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item |  32;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `panama_clientes`
--

DROP TABLE IF EXISTS `panama_clientes`;
CREATE TABLE `panama_clientes` (
  `order_head_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `tipoClienteFE` varchar(2) NOT NULL DEFAULT '02',
  `tipoContribuyente` varchar(1) NOT NULL DEFAULT '',
  `numeroRUC` varchar(20) NOT NULL DEFAULT '',
  `digitoVerificadorRUC` varchar(2) DEFAULT '',
  `razonSocial` varchar(150) NOT NULL DEFAULT '',
  `direccion` varchar(100) NOT NULL DEFAULT '',
  `codigoUbicacion` varchar(8) DEFAULT '',
  `provincia` varchar(50) DEFAULT '',
  `distrito` varchar(50) DEFAULT '',
  `corregimiento` varchar(50) DEFAULT '',
  `telefono1` varchar(12) DEFAULT '',
  `correoElectronico1` varchar(50) DEFAULT '',
  `pais` varchar(10) NOT NULL DEFAULT 'PA',
  `tipoIdentificacion` varchar(50) DEFAULT '',
  `nroIdentificacionExtranjero` varchar(50) DEFAULT '',
  `paisExtranjero` varchar(50) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `panama_familia`
--

DROP TABLE IF EXISTS `panama_familia`;
CREATE TABLE `panama_familia` (
  `familia` varchar(1024) DEFAULT NULL,
  `id` int(11) NOT NULL,
  `segmento_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `panama_menu_item`
--

DROP TABLE IF EXISTS `panama_menu_item`;
CREATE TABLE `panama_menu_item` (
  `menu_item_id` int(11) NOT NULL,
  `codigo` varchar(22) DEFAULT NULL,
  `codigoCPBS` varchar(6) DEFAULT NULL,
  `codigoCPBSAbrev` varchar(4) DEFAULT NULL,
  `unidadMedidaCPBS` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `panama_region`
--

DROP TABLE IF EXISTS `panama_region`;
CREATE TABLE `panama_region` (
  `provincia_id` int(11) NOT NULL,
  `provincia_name` varchar(50) NOT NULL,
  `distrito_id` int(11) NOT NULL,
  `distrito_name` varchar(50) NOT NULL,
  `corregimiento_id` int(11) NOT NULL,
  `corregimiento_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `panama_segmento`
--

DROP TABLE IF EXISTS `panama_segmento`;
CREATE TABLE `panama_segmento` (
  `segmento` varchar(1024) DEFAULT NULL,
  `id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `parties`
--

DROP TABLE IF EXISTS `parties`;
CREATE TABLE `parties` (
  `party_id` int(11) NOT NULL,
  `party_name` varchar(200) DEFAULT NULL,
  `status` int(11) DEFAULT '0',
  `party_type` int(11) DEFAULT '0',
  `table_num` int(11) DEFAULT '0',
  `back_table_num` int(11) DEFAULT '0',
  `table_price` decimal(11,2) DEFAULT NULL,
  `remark` varchar(300) DEFAULT NULL,
  `contract_id` varchar(100) DEFAULT NULL,
  `pre_amount` decimal(11,2) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL,
  `sale_employee` int(11) DEFAULT '-1',
  `customer_name` varchar(100) DEFAULT NULL,
  `customer_tel` varchar(100) DEFAULT NULL,
  `party_time` datetime DEFAULT NULL,
  `insert_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `insert_employee` int(11) DEFAULT '-1',
  `update_employee` int(11) DEFAULT '-1',
  `customer_id` int(11) DEFAULT NULL,
  `table_str` varchar(500) DEFAULT NULL,
  `cus_num` int(11) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `parties_type`
--

DROP TABLE IF EXISTS `parties_type`;
CREATE TABLE `parties_type` (
  `party_type_id` int(11) NOT NULL DEFAULT '0',
  `party_type_name` varchar(200) DEFAULT NULL,
  `msg_template` varchar(500) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `party_default_remark`
--

DROP TABLE IF EXISTS `party_default_remark`;
CREATE TABLE `party_default_remark` (
  `remark_id` int(11) NOT NULL,
  `remark_title` varchar(100) DEFAULT NULL,
  `remark_content` varchar(800) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `party_item`
--

DROP TABLE IF EXISTS `party_item`;
CREATE TABLE `party_item` (
  `party_item_id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `item_course_name` varchar(200) DEFAULT NULL,
  `unit` varchar(100) DEFAULT NULL,
  `num` decimal(11,2) DEFAULT NULL,
  `price` decimal(11,2) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `party_remark`
--

DROP TABLE IF EXISTS `party_remark`;
CREATE TABLE `party_remark` (
  `remark_id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `remark_title` varchar(100) DEFAULT NULL,
  `remark_content` varchar(800) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `party_table`
--

DROP TABLE IF EXISTS `party_table`;
CREATE TABLE `party_table` (
  `party_id` int(11) NOT NULL DEFAULT '0',
  `table_id` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `pax_merchant`
--

DROP TABLE IF EXISTS `pax_merchant`;
CREATE TABLE `pax_merchant` (
  `order_head_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `card_num` varchar(30) DEFAULT NULL,
  `card_type` varchar(20) DEFAULT NULL,
  `card_holder` varchar(20) DEFAULT NULL,
  `entry_mode` varchar(10) DEFAULT NULL,
  `pax_date` varchar(30) DEFAULT NULL,
  `auth_code` varchar(10) DEFAULT NULL,
  `transnum` varchar(10) DEFAULT NULL,
  `appname` varchar(10) DEFAULT NULL,
  `aid` varchar(20) DEFAULT NULL,
  `tc` varchar(20) DEFAULT NULL,
  `subtotal` decimal(11,2) DEFAULT '0.00',
  `sign_file_name` varchar(100) DEFAULT NULL,
  `tip_amount` decimal(11,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `payment`
--

DROP TABLE IF EXISTS `payment`;
CREATE TABLE `payment` (
  `payment_id` int(11) UNSIGNED NOT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL,
  `tender_media_id` int(11) DEFAULT NULL,
  `total` decimal(11,2) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `remark` varchar(30) DEFAULT NULL,
  `payment_time` datetime DEFAULT NULL,
  `pos_device_id` int(11) DEFAULT NULL,
  `rvc_center_id` int(11) DEFAULT NULL,
  `order_detail_id` int(11) DEFAULT NULL,
  `consume_id` int(11) DEFAULT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `wechat_id` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `periods`
--

DROP TABLE IF EXISTS `periods`;
CREATE TABLE `periods` (
  `period_id` int(11) NOT NULL,
  `period_name` varchar(30) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `is_serving_period` bit(1) DEFAULT NULL,
  `active_day` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `periods`
--
DROP TRIGGER IF EXISTS `trigger_periods_add`;
DELIMITER $$
CREATE TRIGGER `trigger_periods_add` AFTER INSERT ON `periods` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_periods_del`;
DELIMITER $$
CREATE TRIGGER `trigger_periods_del` AFTER DELETE ON `periods` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_periods_update`;
DELIMITER $$
CREATE TRIGGER `trigger_periods_update` AFTER UPDATE ON `periods` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `period_class`
--

DROP TABLE IF EXISTS `period_class`;
CREATE TABLE `period_class` (
  `period_class_id` int(11) NOT NULL DEFAULT '0',
  `period_class_name` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `period_class`
--
DROP TRIGGER IF EXISTS `trigger_period_class_add`;
DELIMITER $$
CREATE TRIGGER `trigger_period_class_add` AFTER INSERT ON `period_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_period_class_del`;
DELIMITER $$
CREATE TRIGGER `trigger_period_class_del` AFTER DELETE ON `period_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_period_class_update`;
DELIMITER $$
CREATE TRIGGER `trigger_period_class_update` AFTER UPDATE ON `period_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `phone_history`
--

DROP TABLE IF EXISTS `phone_history`;
CREATE TABLE `phone_history` (
  `history_id` int(11) NOT NULL,
  `time_of_call` datetime NOT NULL,
  `customer_phone` varchar(20) NOT NULL,
  `customer_name` varchar(20) NOT NULL DEFAULT '',
  `phone_state` int(2) NOT NULL DEFAULT '0',
  `order_state` int(2) NOT NULL DEFAULT '0',
  `order_id` int(11) NOT NULL DEFAULT '0',
  `customer_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `pic_version`
--

DROP TABLE IF EXISTS `pic_version`;
CREATE TABLE `pic_version` (
  `major_version` int(11) DEFAULT NULL,
  `local_version` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `pos_keys`
--

DROP TABLE IF EXISTS `pos_keys`;
CREATE TABLE `pos_keys` (
  `id` int(11) NOT NULL DEFAULT '0',
  `pos_type` int(11) DEFAULT NULL,
  `stamp` varbinary(512) DEFAULT NULL,
  `pos` varbinary(512) DEFAULT NULL,
  `pad` varbinary(512) DEFAULT NULL,
  `pda` varbinary(512) DEFAULT NULL,
  `smenu` varbinary(512) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `practice`
--

DROP TABLE IF EXISTS `practice`;
CREATE TABLE `practice` (
  `practice_id` int(11) NOT NULL,
  `practice_name` varchar(300) DEFAULT NULL,
  `practice_group` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `practice_group`
--

DROP TABLE IF EXISTS `practice_group`;
CREATE TABLE `practice_group` (
  `practice_group_id` int(11) NOT NULL DEFAULT '0',
  `practice_group_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `pre_order`
--

DROP TABLE IF EXISTS `pre_order`;
CREATE TABLE `pre_order` (
  `pre_order_id` int(11) NOT NULL,
  `pre_order_name` varchar(40) DEFAULT '',
  `customer_id` int(11) DEFAULT '0',
  `customer_name` varchar(40) DEFAULT '',
  `telephone_1` varchar(40) DEFAULT '',
  `telephone_2` varchar(40) DEFAULT '',
  `company` varchar(100) DEFAULT '',
  `pre_amount` double(15,2) DEFAULT '0.00',
  `order_type` int(11) DEFAULT '1',
  `type` int(11) DEFAULT NULL,
  `rvc_center_id` int(11) DEFAULT '0',
  `rvc_center_name` varchar(40) DEFAULT '',
  `table_id` int(11) NOT NULL DEFAULT '0',
  `table_name` varchar(40) DEFAULT '',
  `customer_num` int(11) DEFAULT '0',
  `description` varchar(200) DEFAULT '',
  `pre_order_status` int(11) DEFAULT '0',
  `preorder_employee_id` int(11) DEFAULT '0',
  `preorder_employee_name` varchar(40) DEFAULT '',
  `preorder_time` datetime DEFAULT NULL,
  `disorder_employee_id` int(11) DEFAULT '0',
  `disorder_employee_name` varchar(40) DEFAULT '',
  `auth_employee_id` int(11) DEFAULT '0',
  `auth_employee_name` varchar(40) DEFAULT '',
  `sail_employee_id` int(11) DEFAULT '0',
  `sail_employee_name` varchar(40) DEFAULT NULL,
  `disorder_time` datetime DEFAULT NULL,
  `disorder_reason` varchar(255) DEFAULT '',
  `arrived_time` datetime DEFAULT NULL,
  `actual_arrived_time` datetime DEFAULT NULL,
  `amount` double(15,2) DEFAULT '0.00',
  `order_head_id` int(11) DEFAULT NULL,
  `edit_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `pre_order`
--
DROP TRIGGER IF EXISTS `trigger_pre_order_insert`;
DELIMITER $$
CREATE TRIGGER `trigger_pre_order_insert` BEFORE INSERT ON `pre_order` FOR EACH ROW BEGIN
	SET NEW.edit_time = now();
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_pre_order_update`;
DELIMITER $$
CREATE TRIGGER `trigger_pre_order_update` BEFORE UPDATE ON `pre_order` FOR EACH ROW BEGIN
	SET NEW.edit_time = now();
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `pre_order_detail`
--

DROP TABLE IF EXISTS `pre_order_detail`;
CREATE TABLE `pre_order_detail` (
  `preorder_detail_id` int(11) NOT NULL,
  `pre_order_id` int(11) DEFAULT NULL,
  `menu_item_id` int(11) DEFAULT NULL,
  `menu_item_name` varchar(40) DEFAULT '',
  `price` double(15,2) DEFAULT NULL,
  `quantity` float DEFAULT NULL,
  `unit` varchar(30) DEFAULT NULL,
  `should_amount` double(15,2) DEFAULT NULL,
  `condiment_belong_item` int(11) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `price_scheme`
--

DROP TABLE IF EXISTS `price_scheme`;
CREATE TABLE `price_scheme` (
  `menu_item_id` int(11) NOT NULL DEFAULT '0',
  `menu_item_name` varchar(20) DEFAULT NULL,
  `item_price` double DEFAULT NULL,
  `disable` int(11) DEFAULT '0',
  `group_id` int(11) NOT NULL DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `price_scheme`
--
DROP TRIGGER IF EXISTS `trigger_price_scheme_add`;
DELIMITER $$
CREATE TRIGGER `trigger_price_scheme_add` AFTER INSERT ON `price_scheme` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_price_scheme_del`;
DELIMITER $$
CREATE TRIGGER `trigger_price_scheme_del` AFTER DELETE ON `price_scheme` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_price_scheme_update`;
DELIMITER $$
CREATE TRIGGER `trigger_price_scheme_update` AFTER UPDATE ON `price_scheme` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `price_scheme_group`
--

DROP TABLE IF EXISTS `price_scheme_group`;
CREATE TABLE `price_scheme_group` (
  `group_id` int(11) NOT NULL,
  `group_name` varchar(300) DEFAULT NULL,
  `period_class` int(11) DEFAULT '-1',
  `place_class` int(11) DEFAULT '-1',
  `is_discount` int(11) DEFAULT '0',
  `is_service` int(11) DEFAULT '0',
  `from_time` time DEFAULT NULL,
  `to_time` time DEFAULT NULL,
  `week` int(11) DEFAULT '512',
  `month` int(11) DEFAULT NULL,
  `begin` date DEFAULT NULL,
  `end` date DEFAULT NULL,
  `disable` int(11) DEFAULT '0',
  `eat_type` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `price_scheme_group`
--
DROP TRIGGER IF EXISTS `trigger_price_scheme_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_price_scheme_group_add` AFTER INSERT ON `price_scheme_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_price_scheme_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_price_scheme_group_del` AFTER DELETE ON `price_scheme_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_price_scheme_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_price_scheme_group_update` AFTER UPDATE ON `price_scheme_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `print_class`
--

DROP TABLE IF EXISTS `print_class`;
CREATE TABLE `print_class` (
  `print_class_id` int(11) NOT NULL,
  `print_class_number` int(11) DEFAULT NULL,
  `print_class_name` varchar(30) DEFAULT NULL,
  `customer_receipt` bit(1) DEFAULT b'0',
  `local_order_receipt` bit(1) DEFAULT b'0',
  `check` bit(1) DEFAULT b'0',
  `journal` bit(1) DEFAULT b'0',
  `fiscal_cash_register` bit(1) DEFAULT b'0',
  `print_in_red` bit(1) DEFAULT b'0',
  `remote_device` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `print_class`
--
DROP TRIGGER IF EXISTS `trigger_print_class2_add`;
DELIMITER $$
CREATE TRIGGER `trigger_print_class2_add` AFTER INSERT ON `print_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_print_class2_del`;
DELIMITER $$
CREATE TRIGGER `trigger_print_class2_del` AFTER DELETE ON `print_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_print_class2_update`;
DELIMITER $$
CREATE TRIGGER `trigger_print_class2_update` AFTER UPDATE ON `print_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `print_class_relation`
--

DROP TABLE IF EXISTS `print_class_relation`;
CREATE TABLE `print_class_relation` (
  `print_class_id` int(11) NOT NULL DEFAULT '0',
  `print_device_id` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `print_class_relation`
--
DROP TRIGGER IF EXISTS `trigger_print_class_relation_add`;
DELIMITER $$
CREATE TRIGGER `trigger_print_class_relation_add` AFTER INSERT ON `print_class_relation` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_print_class_relation_del`;
DELIMITER $$
CREATE TRIGGER `trigger_print_class_relation_del` AFTER DELETE ON `print_class_relation` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_print_class_relation_update`;
DELIMITER $$
CREATE TRIGGER `trigger_print_class_relation_update` AFTER UPDATE ON `print_class_relation` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `print_devices`
--

DROP TABLE IF EXISTS `print_devices`;
CREATE TABLE `print_devices` (
  `print_device_id` int(11) NOT NULL,
  `print_device_name` varchar(30) DEFAULT NULL,
  `header` int(11) DEFAULT NULL,
  `trailer` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `backup_device_id` int(11) DEFAULT NULL,
  `redirection_device_id` int(11) DEFAULT NULL,
  `check_info_print` int(11) DEFAULT NULL,
  `print_table_number` bit(1) DEFAULT NULL,
  `com_port` int(11) DEFAULT '0',
  `baud_rate` int(11) DEFAULT NULL,
  `parity_type` int(11) DEFAULT NULL,
  `num_data_bit` int(11) DEFAULT NULL,
  `num_stop_bits` int(11) DEFAULT NULL,
  `is_print_note` int(11) DEFAULT '1',
  `printer_name` varchar(300) DEFAULT NULL,
  `flow_control` int(11) DEFAULT '1',
  `paper_width` int(11) DEFAULT NULL,
  `beep` int(11) DEFAULT '0',
  `split_print` bit(1) DEFAULT b'0',
  `print_label` bit(1) DEFAULT b'0',
  `eat_type_skip` int(11) DEFAULT '0',
  `insert_course` bit(1) DEFAULT b'0',
  `backup_printer_enabled` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `print_devices`
--
DROP TRIGGER IF EXISTS `trigger_print_devices_add`;
DELIMITER $$
CREATE TRIGGER `trigger_print_devices_add` AFTER INSERT ON `print_devices` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_print_devices_del`;
DELIMITER $$
CREATE TRIGGER `trigger_print_devices_del` AFTER DELETE ON `print_devices` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_print_devices_update`;
DELIMITER $$
CREATE TRIGGER `trigger_print_devices_update` AFTER UPDATE ON `print_devices` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET major_group = major_group | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `print_task`
--

DROP TABLE IF EXISTS `print_task`;
CREATE TABLE `print_task` (
  `id` int(11) UNSIGNED NOT NULL,
  `data` varchar(21840) DEFAULT NULL,
  `time` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `print_templates`
--

DROP TABLE IF EXISTS `print_templates`;
CREATE TABLE `print_templates` (
  `template_id` int(11) NOT NULL,
  `template_name` varchar(255) DEFAULT NULL,
  `content` varchar(20480) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `reasons`
--

DROP TABLE IF EXISTS `reasons`;
CREATE TABLE `reasons` (
  `reason_id` int(11) NOT NULL,
  `reason_number` int(11) DEFAULT NULL,
  `reason_name` varchar(30) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `is_voids` bit(1) DEFAULT NULL,
  `is_returns` bit(1) DEFAULT b'0',
  `is_timecard` bit(1) DEFAULT b'0',
  `is_requests` bit(1) DEFAULT NULL,
  `is_terminations` bit(1) DEFAULT NULL,
  `is_absence` bit(1) DEFAULT NULL,
  `is_playrate` bit(1) DEFAULT NULL,
  `is_other` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `reasons`
--
DROP TRIGGER IF EXISTS `trigger_reasons_add`;
DELIMITER $$
CREATE TRIGGER `trigger_reasons_add` AFTER INSERT ON `reasons` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_reasons_del`;
DELIMITER $$
CREATE TRIGGER `trigger_reasons_del` AFTER DELETE ON `reasons` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_reasons_update`;
DELIMITER $$
CREATE TRIGGER `trigger_reasons_update` AFTER UPDATE ON `reasons` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET employee = employee | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `report`
--

DROP TABLE IF EXISTS `report`;
CREATE TABLE `report` (
  `report_id` int(11) NOT NULL,
  `report_name` varchar(40) DEFAULT NULL,
  `report_class_id` int(11) DEFAULT NULL,
  `is_date_range` bit(1) DEFAULT b'0',
  `is_rvc_range` bit(1) DEFAULT b'0',
  `is_number_range` bit(1) DEFAULT b'0',
  `number_range_type` int(11) DEFAULT '-1',
  `template_filename` varchar(256) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `report_1`
--

DROP TABLE IF EXISTS `report_1`;
CREATE TABLE `report_1` (
  `report_id` int(11) NOT NULL,
  `report_name` varchar(40) DEFAULT NULL,
  `report_class_id` int(11) DEFAULT NULL,
  `is_date_range` bit(1) DEFAULT b'0',
  `is_rvc_range` bit(1) DEFAULT b'0',
  `is_number_range` bit(1) DEFAULT b'0',
  `number_range_type` int(11) DEFAULT '-1',
  `template_filename` varchar(256) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `report_2`
--

DROP TABLE IF EXISTS `report_2`;
CREATE TABLE `report_2` (
  `report_id` int(11) NOT NULL,
  `report_name` varchar(40) DEFAULT NULL,
  `report_class_id` int(11) DEFAULT NULL,
  `is_date_range` bit(1) DEFAULT b'0',
  `is_rvc_range` bit(1) DEFAULT b'0',
  `is_number_range` bit(1) DEFAULT b'0',
  `number_range_type` int(11) DEFAULT '-1',
  `template_filename` varchar(256) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `report_class`
--

DROP TABLE IF EXISTS `report_class`;
CREATE TABLE `report_class` (
  `report_class_id` int(11) NOT NULL DEFAULT '0',
  `report_class_name` varchar(40) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `report_class_1`
--

DROP TABLE IF EXISTS `report_class_1`;
CREATE TABLE `report_class_1` (
  `report_class_id` int(11) NOT NULL DEFAULT '0',
  `report_class_name` varchar(40) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `report_class_2`
--

DROP TABLE IF EXISTS `report_class_2`;
CREATE TABLE `report_class_2` (
  `report_class_id` int(11) NOT NULL DEFAULT '0',
  `report_class_name` varchar(40) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `restaurant`
--

DROP TABLE IF EXISTS `restaurant`;
CREATE TABLE `restaurant` (
  `restaurant_id` int(11) NOT NULL,
  `restaurant_name` varchar(30) DEFAULT NULL,
  `location_name_1` varchar(200) DEFAULT NULL,
  `location_name_2` varchar(300) DEFAULT NULL,
  `server_network_node` int(11) DEFAULT NULL,
  `backup_server_node` int(11) DEFAULT NULL,
  `default_printer_name` varchar(30) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `start_day` int(11) DEFAULT NULL,
  `autoinc_business_date` bit(1) DEFAULT NULL,
  `fiscal_year_date` datetime DEFAULT NULL,
  `type` int(11) DEFAULT NULL,
  `fiscal_period_name` varchar(300) DEFAULT NULL,
  `floating_fical_date` bit(1) DEFAULT NULL,
  `fixed_period` bit(1) DEFAULT NULL,
  `number_of_days` int(11) DEFAULT NULL,
  `currency_name` varchar(30) DEFAULT NULL,
  `decimal_places` int(11) DEFAULT NULL,
  `decimal_char` varchar(1) DEFAULT NULL,
  `is_input_chk` int(11) DEFAULT '0',
  `auto_send_type` int(11) DEFAULT '0',
  `db_back_folder` varchar(500) DEFAULT NULL,
  `wechat_mchid` varchar(32) DEFAULT NULL,
  `wechat_appid` varchar(32) DEFAULT NULL,
  `ali_mchid` varchar(32) DEFAULT NULL,
  `ali_appid` varchar(64) DEFAULT NULL,
  `postcode` varchar(20) DEFAULT NULL,
  `version` tinyint(4) NOT NULL DEFAULT '3',
  `menu1` varchar(30) DEFAULT 'Menu 1',
  `menu2` varchar(30) DEFAULT 'Menu 2',
  `check_num0` int(11) DEFAULT '0',
  `check_num1` int(11) DEFAULT '0',
  `check_num2` int(11) DEFAULT '0',
  `check_num3` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `restaurant`
--
DROP TRIGGER IF EXISTS `trigger_restaurant_add`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_add` AFTER INSERT ON `restaurant` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_restaurant_del`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_del` AFTER DELETE ON `restaurant` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_restaurant_update`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_update` AFTER UPDATE ON `restaurant` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `restaurant_takeout_hours`
--

DROP TABLE IF EXISTS `restaurant_takeout_hours`;
CREATE TABLE `restaurant_takeout_hours` (
  `rtake_hours_id` int(11) NOT NULL DEFAULT '0',
  `weekday` int(11) DEFAULT NULL,
  `start_time1` time DEFAULT NULL,
  `end_time1` time DEFAULT NULL,
  `start_time2` time DEFAULT NULL,
  `end_time2` time DEFAULT NULL,
  `closed` tinyint(4) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `restaurant_takeout_hours`
--
DROP TRIGGER IF EXISTS `trigger_restaurant_takeout_hours_add`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_takeout_hours_add` AFTER INSERT ON `restaurant_takeout_hours` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_restaurant_takeout_hours_del`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_takeout_hours_del` AFTER DELETE ON `restaurant_takeout_hours` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_restaurant_takeout_hours_update`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_takeout_hours_update` AFTER UPDATE ON `restaurant_takeout_hours` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `restaurant_takeout_info`
--

DROP TABLE IF EXISTS `restaurant_takeout_info`;
CREATE TABLE `restaurant_takeout_info` (
  `rtake_info_id` int(11) NOT NULL DEFAULT '0',
  `address` varchar(500) DEFAULT NULL,
  `longitude` varchar(20) DEFAULT NULL,
  `latitude` varchar(20) DEFAULT NULL,
  `img1` varchar(200) DEFAULT NULL,
  `img2` varchar(200) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `phone1` varchar(50) DEFAULT NULL,
  `phone2` varchar(50) DEFAULT NULL,
  `phone3` varchar(50) DEFAULT NULL,
  `phone4` varchar(50) DEFAULT NULL,
  `phone5` varchar(50) DEFAULT NULL,
  `attention` varchar(200) DEFAULT NULL,
  `take_out_slu` int(11) DEFAULT '1',
  `show_total` tinyint(4) DEFAULT '1',
  `show_zero` tinyint(4) DEFAULT '0',
  `enable_service` int(11) DEFAULT '1',
  `language_type` int(11) DEFAULT '1',
  `country_code` varchar(20) DEFAULT NULL,
  `min_amount` double(15,2) DEFAULT NULL,
  `eat_type` int(11) NOT NULL DEFAULT '255',
  `cny_exrate` float DEFAULT NULL,
  `img3` varchar(200) DEFAULT NULL,
  `img4` varchar(200) DEFAULT NULL,
  `img5` varchar(200) DEFAULT NULL,
  `img6` varchar(200) DEFAULT NULL,
  `img7` varchar(200) DEFAULT NULL,
  `img8` varchar(200) DEFAULT NULL,
  `country_id` int(11) DEFAULT NULL,
  `ccTLD` varchar(10) DEFAULT NULL,
  `static_map_url` varchar(1000) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `delivery_minute` int(11) DEFAULT '30',
  `collect_minute` int(11) DEFAULT '15',
  `link1` varchar(200) DEFAULT NULL,
  `link2` varchar(200) DEFAULT NULL,
  `link3` varchar(200) DEFAULT NULL,
  `link4` varchar(200) DEFAULT NULL,
  `link5` varchar(200) DEFAULT NULL,
  `link6` varchar(200) DEFAULT NULL,
  `link7` varchar(200) DEFAULT NULL,
  `link8` varchar(200) DEFAULT NULL,
  `country_code2` varchar(255) DEFAULT NULL,
  `show_name2` tinyint(4) DEFAULT '0',
  `qrcode_expire` int(11) DEFAULT '0',
  `img_logo` varchar(200) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `restaurant_takeout_info`
--
DROP TRIGGER IF EXISTS `trigger_restaurant_takeout_info_add`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_takeout_info_add` AFTER INSERT ON `restaurant_takeout_info` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_restaurant_takeout_info_del`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_takeout_info_del` AFTER DELETE ON `restaurant_takeout_info` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_restaurant_takeout_info_update`;
DELIMITER $$
CREATE TRIGGER `trigger_restaurant_takeout_info_update` AFTER UPDATE ON `restaurant_takeout_info` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `retail`
--

DROP TABLE IF EXISTS `retail`;
CREATE TABLE `retail` (
  `id` int(11) NOT NULL,
  `retailno` int(10) UNSIGNED DEFAULT NULL,
  `memberid` int(11) DEFAULT '0',
  `createdate` datetime DEFAULT NULL,
  `yingshou` double DEFAULT NULL,
  `shishou` double DEFAULT NULL,
  `branchid` int(11) DEFAULT NULL,
  `salesid` int(11) DEFAULT NULL,
  `machineid` int(11) DEFAULT NULL,
  `member_type` int(11) DEFAULT NULL,
  `checkNO` varchar(50) DEFAULT '0',
  `Pay_Type` int(11) DEFAULT NULL,
  `discount` double DEFAULT NULL,
  `machine_number` varchar(50) DEFAULT NULL,
  `saleType` int(11) DEFAULT '0',
  `IsCount` char(10) DEFAULT '0',
  `SCDiscount` double DEFAULT NULL,
  `PWDDiscount` double DEFAULT NULL,
  `ReturnOR` varchar(50) DEFAULT NULL,
  `CASH` double DEFAULT NULL,
  `CHANGE` double DEFAULT NULL,
  `DiscountType` int(11) DEFAULT NULL,
  `VATExemptSales` double DEFAULT NULL,
  `VATSales` double DEFAULT NULL,
  `VATAmount` double DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `guest_num` int(11) DEFAULT NULL,
  `servicefee` double DEFAULT NULL,
  `eatType` int(11) DEFAULT NULL,
  `order_head_id` int(11) DEFAULT NULL,
  `check_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `retail_detail`
--

DROP TABLE IF EXISTS `retail_detail`;
CREATE TABLE `retail_detail` (
  `id` int(10) UNSIGNED NOT NULL,
  `jleeitemid` int(11) DEFAULT NULL,
  `quatity` double DEFAULT NULL,
  `price` double DEFAULT NULL,
  `nowprice` double DEFAULT NULL,
  `total` double DEFAULT NULL,
  `discount` double DEFAULT NULL,
  `w_Price` double DEFAULT NULL,
  `item_no` varchar(50) DEFAULT NULL,
  `item_subno` varchar(50) DEFAULT NULL,
  `item_RealNo` varchar(50) DEFAULT NULL,
  `item_clsno` varchar(50) DEFAULT NULL,
  `CategoryID` int(11) DEFAULT NULL,
  `now_w_Price` double DEFAULT NULL,
  `saleid` int(11) DEFAULT NULL,
  `branchid` int(11) DEFAULT '0',
  `IsUpdate` int(11) DEFAULT '0',
  `FLAG_Remark` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `retail_paytype`
--

DROP TABLE IF EXISTS `retail_paytype`;
CREATE TABLE `retail_paytype` (
  `id` int(10) UNSIGNED NOT NULL,
  `retailid` int(11) DEFAULT NULL,
  `pay_type` int(11) DEFAULT NULL,
  `amount` double DEFAULT NULL,
  `createdate` datetime DEFAULT NULL,
  `CreditCardNumber` varchar(50) DEFAULT NULL,
  `Remark` varchar(50) DEFAULT NULL,
  `other1` double DEFAULT NULL,
  `other2` double DEFAULT NULL,
  `other3` varchar(50) DEFAULT NULL,
  `other4` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `rm_pay_key`
--

DROP TABLE IF EXISTS `rm_pay_key`;
CREATE TABLE `rm_pay_key` (
  `clientId` varchar(100) DEFAULT NULL,
  `clientSecret` varchar(100) DEFAULT NULL,
  `storeId` varchar(100) DEFAULT NULL,
  `terminalId` varchar(100) DEFAULT NULL,
  `payStyle` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `rvc_center`
--

DROP TABLE IF EXISTS `rvc_center`;
CREATE TABLE `rvc_center` (
  `rvc_center_id` int(11) NOT NULL,
  `rvc_center_name` varchar(30) DEFAULT NULL,
  `cc_voucher_header` int(11) DEFAULT NULL,
  `consumption_limit` int(11) DEFAULT '-1',
  `row_menu_printer` int(11) DEFAULT '-1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `rvc_center`
--
DROP TRIGGER IF EXISTS `trigger_rvc_center_add`;
DELIMITER $$
CREATE TRIGGER `trigger_rvc_center_add` AFTER INSERT ON `rvc_center` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_rvc_center_del`;
DELIMITER $$
CREATE TRIGGER `trigger_rvc_center_del` AFTER DELETE ON `rvc_center` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_rvc_center_update`;
DELIMITER $$
CREATE TRIGGER `trigger_rvc_center_update` AFTER UPDATE ON `rvc_center` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `rvc_class`
--

DROP TABLE IF EXISTS `rvc_class`;
CREATE TABLE `rvc_class` (
  `rvc_class_id` int(11) NOT NULL DEFAULT '0',
  `rvc_class_name` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `rvc_class`
--
DROP TRIGGER IF EXISTS `trigger_rvc_class_add`;
DELIMITER $$
CREATE TRIGGER `trigger_rvc_class_add` AFTER INSERT ON `rvc_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_rvc_class_del`;
DELIMITER $$
CREATE TRIGGER `trigger_rvc_class_del` AFTER DELETE ON `rvc_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 2;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_rvc_class_update`;
DELIMITER $$
CREATE TRIGGER `trigger_rvc_class_update` AFTER UPDATE ON `rvc_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `service_tip`
--

DROP TABLE IF EXISTS `service_tip`;
CREATE TABLE `service_tip` (
  `service_tip_id` int(11) NOT NULL,
  `service_tip_name` varchar(40) DEFAULT NULL,
  `type` int(11) NOT NULL DEFAULT '0',
  `print_class` int(11) DEFAULT NULL,
  `menu_level_class` int(11) DEFAULT NULL,
  `privilege` int(11) DEFAULT NULL,
  `nlu` int(11) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL,
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `category` int(11) DEFAULT NULL,
  `percent` decimal(11,2) DEFAULT NULL,
  `preset` bit(1) DEFAULT NULL,
  `reference_required` bit(1) DEFAULT NULL,
  `auto_sys_chg` bit(1) DEFAULT NULL,
  `seat_filter_exit` bit(1) DEFAULT NULL,
  `assume_entire_amount` bit(1) DEFAULT NULL,
  `not_with_seat` bit(1) DEFAULT NULL,
  `employee_meal` bit(1) DEFAULT b'0',
  `discount_over_threshold` bit(1) DEFAULT NULL,
  `limit_discount` bit(1) DEFAULT NULL,
  `discount_last_item` bit(1) DEFAULT NULL,
  `single_seat` bit(1) DEFAULT NULL,
  `prorated_subtotal` bit(1) DEFAULT NULL,
  `select_discount` bit(1) DEFAULT NULL,
  `period_class_id` int(11) DEFAULT NULL,
  `rvc_class_id` int(11) DEFAULT NULL,
  `display_name` char(60) DEFAULT NULL,
  `not_print` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `service_tip`
--
DROP TRIGGER IF EXISTS `trigger_service_tip_add`;
DELIMITER $$
CREATE TRIGGER `trigger_service_tip_add` AFTER INSERT ON `service_tip` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_service_tip_del`;
DELIMITER $$
CREATE TRIGGER `trigger_service_tip_del` AFTER DELETE ON `service_tip` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_service_tip_update`;
DELIMITER $$
CREATE TRIGGER `trigger_service_tip_update` AFTER UPDATE ON `service_tip` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `serving_period_class`
--

DROP TABLE IF EXISTS `serving_period_class`;
CREATE TABLE `serving_period_class` (
  `period_class_id` int(11) NOT NULL DEFAULT '0',
  `period` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `serving_period_class`
--
DROP TRIGGER IF EXISTS `trigger_serving_period_class_add`;
DELIMITER $$
CREATE TRIGGER `trigger_serving_period_class_add` AFTER INSERT ON `serving_period_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET periods = periods | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `serving_rvc_class`
--

DROP TABLE IF EXISTS `serving_rvc_class`;
CREATE TABLE `serving_rvc_class` (
  `rvc_class_id` int(11) NOT NULL DEFAULT '0',
  `rvc_center_id` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `serving_rvc_class`
--
DROP TRIGGER IF EXISTS `trigger_serving_rvc_class_add`;
DELIMITER $$
CREATE TRIGGER `trigger_serving_rvc_class_add` AFTER INSERT ON `serving_rvc_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 2;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `shift_info`
--

DROP TABLE IF EXISTS `shift_info`;
CREATE TABLE `shift_info` (
  `shift_id` int(11) NOT NULL,
  `type` int(11) DEFAULT NULL,
  `employee_id` int(11) NOT NULL,
  `employee_name` varchar(10) DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `pos_device_id` int(11) DEFAULT NULL,
  `pos_name` varchar(10) DEFAULT NULL,
  `detail` varchar(10240) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `shouqianba_keys`
--

DROP TABLE IF EXISTS `shouqianba_keys`;
CREATE TABLE `shouqianba_keys` (
  `workstations_id` int(11) NOT NULL,
  `terminal_sn` varchar(255) NOT NULL,
  `terminal_key` varchar(255) NOT NULL,
  `update_time` datetime NOT NULL,
  `code` varchar(255) NOT NULL,
  `device_id` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `sms_templates`
--

DROP TABLE IF EXISTS `sms_templates`;
CREATE TABLE `sms_templates` (
  `sms_id` int(11) NOT NULL DEFAULT '0',
  `sms_name` varchar(255) DEFAULT NULL,
  `sms_content` varchar(1000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `storage`
--

DROP TABLE IF EXISTS `storage`;
CREATE TABLE `storage` (
  `id` int(11) NOT NULL,
  `vip` varchar(50) DEFAULT NULL,
  `info` varchar(500) DEFAULT NULL,
  `employee` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `del_date` date DEFAULT NULL,
  `del_employee` int(11) DEFAULT NULL,
  `is_use` bit(1) DEFAULT b'1',
  `last_msg_date` date DEFAULT NULL COMMENT '上次发送短信通知时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `tables`
--

DROP TABLE IF EXISTS `tables`;
CREATE TABLE `tables` (
  `table_id` int(11) NOT NULL DEFAULT '0',
  `table_name` varchar(30) DEFAULT NULL,
  `seat_num` int(11) DEFAULT NULL,
  `table_status` int(11) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `rvc_center_id` int(11) DEFAULT NULL,
  `floor` int(11) DEFAULT '1',
  `icon` varchar(256) DEFAULT NULL,
  `consumption_limit` int(11) DEFAULT NULL,
  `row_menu_printer` int(11) DEFAULT '-1',
  `party_table` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tables`
--
DROP TRIGGER IF EXISTS `trigger_tables_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tables_add` AFTER INSERT ON `tables` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tables_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tables_del` AFTER DELETE ON `tables` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tables = tables | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tables_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tables_update` AFTER UPDATE ON `tables` FOR EACH ROW BEGIN
    IF OLD.table_status = NEW.table_status THEN
        UPDATE webreport_setting SET tables = tables | 1;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `table_status`
--

DROP TABLE IF EXISTS `table_status`;
CREATE TABLE `table_status` (
  `table_stauts_id` int(11) NOT NULL DEFAULT '0',
  `table_status_name` varchar(30) DEFAULT NULL,
  `icon` varchar(256) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `takeout_tag`
--

DROP TABLE IF EXISTS `takeout_tag`;
CREATE TABLE `takeout_tag` (
  `tag_id` int(11) NOT NULL DEFAULT '0',
  `tag_name` varchar(100) DEFAULT NULL,
  `status` tinyint(4) DEFAULT '1',
  `index` int(11) DEFAULT '100'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `takeout_tag`
--
DROP TRIGGER IF EXISTS `trigger_takeout_tag_add`;
DELIMITER $$
CREATE TRIGGER `trigger_takeout_tag_add` AFTER INSERT ON `takeout_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_takeout_tag_del`;
DELIMITER $$
CREATE TRIGGER `trigger_takeout_tag_del` AFTER DELETE ON `takeout_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_takeout_tag_update`;
DELIMITER $$
CREATE TRIGGER `trigger_takeout_tag_update` AFTER UPDATE ON `takeout_tag` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET menu_item = menu_item | 16;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `tax`
--

DROP TABLE IF EXISTS `tax`;
CREATE TABLE `tax` (
  `tax_id` int(11) NOT NULL DEFAULT '0',
  `tax_name` varchar(300) DEFAULT NULL,
  `tax_group` int(11) DEFAULT NULL,
  `display_name` varchar(300) DEFAULT NULL,
  `tax_type` int(11) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL,
  `start_amount` decimal(11,2) DEFAULT NULL,
  `end_amount` decimal(11,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tax`
--
DROP TRIGGER IF EXISTS `trigger_tax_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_add` AFTER INSERT ON `tax` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tax_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_del` AFTER DELETE ON `tax` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tax_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_update` AFTER UPDATE ON `tax` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `tax_details`
--

DROP TABLE IF EXISTS `tax_details`;
CREATE TABLE `tax_details` (
  `order_head_id` int(11) NOT NULL DEFAULT '0',
  `check_id` int(11) NOT NULL DEFAULT '0',
  `tax_group_id` int(11) NOT NULL DEFAULT '0',
  `tax_group_name` varchar(255) NOT NULL DEFAULT '',
  `tax_rate` decimal(11,2) NOT NULL DEFAULT '0.00',
  `tax_fee` decimal(11,2) NOT NULL DEFAULT '0.00',
  `total_without_tax` decimal(11,2) NOT NULL DEFAULT '0.00',
  `total_with_tax` decimal(11,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `tax_group`
--

DROP TABLE IF EXISTS `tax_group`;
CREATE TABLE `tax_group` (
  `tax_group_id` int(11) NOT NULL DEFAULT '0',
  `tax_group_name` varchar(300) DEFAULT NULL,
  `special_tax_group` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tax_group`
--
DROP TRIGGER IF EXISTS `trigger_tax_group_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_group_add` AFTER INSERT ON `tax_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tax_group_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_group_del` AFTER DELETE ON `tax_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tax_group_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_group_update` AFTER UPDATE ON `tax_group` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `tax_primary`
--

DROP TABLE IF EXISTS `tax_primary`;
CREATE TABLE `tax_primary` (
  `tax_id` int(11) NOT NULL DEFAULT '-1',
  `tax_name` varchar(20) DEFAULT NULL,
  `amount` double DEFAULT NULL,
  `include_service` int(11) DEFAULT '0',
  `round` int(11) DEFAULT '0',
  `tax_type` int(11) DEFAULT NULL,
  `start_amount` decimal(11,2) DEFAULT NULL,
  `end_amount` decimal(11,2) DEFAULT NULL,
  `include_discount` int(11) DEFAULT '0',
  `service_tax_group` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tax_primary`
--
DROP TRIGGER IF EXISTS `trigger_tax_primary_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_primary_add` AFTER INSERT ON `tax_primary` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tax_primary_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_primary_del` AFTER DELETE ON `tax_primary` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tax_primary_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tax_primary_update` AFTER UPDATE ON `tax_primary` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET res_info = res_info | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `tender_media`
--

DROP TABLE IF EXISTS `tender_media`;
CREATE TABLE `tender_media` (
  `tender_media_id` int(11) NOT NULL DEFAULT '0',
  `tender_media_name` varchar(30) DEFAULT NULL,
  `type` int(11) DEFAULT NULL,
  `date_from` datetime DEFAULT NULL,
  `date_to` datetime DEFAULT NULL,
  `slu` int(11) DEFAULT NULL,
  `print_class` int(11) DEFAULT NULL,
  `menu_level_class` int(11) DEFAULT NULL,
  `privilege` int(11) DEFAULT NULL,
  `category` int(11) DEFAULT NULL,
  `nlu` varchar(20) DEFAULT NULL,
  `open_drawer` bit(1) DEFAULT NULL,
  `currency_conversion` bit(1) DEFAULT NULL,
  `reference_required` bit(1) DEFAULT NULL,
  `exempt_service` bit(1) DEFAULT NULL,
  `employee_meal` bit(1) DEFAULT NULL,
  `paid_full` bit(1) DEFAULT NULL,
  `require_amount_entry` bit(1) DEFAULT NULL,
  `declare_tips_paid` bit(1) DEFAULT NULL,
  `item_is_shareable` bit(1) DEFAULT NULL,
  `gross_receipts` bit(1) DEFAULT NULL,
  `charge_receipts` bit(1) DEFAULT NULL,
  `cash_register_credit` bit(1) DEFAULT NULL,
  `tax_exempt_coupon` bit(1) DEFAULT NULL,
  `charged_tip` int(11) DEFAULT NULL,
  `enable_halo` bit(1) DEFAULT NULL,
  `halo_limits_amount` int(11) DEFAULT '0',
  `halo_limits_overtender` int(11) DEFAULT NULL,
  `halo` int(11) DEFAULT NULL,
  `print_summary_totals` bit(1) DEFAULT NULL,
  `print_vat_lines` bit(1) DEFAULT NULL,
  `print_sales_itemizer` bit(1) DEFAULT NULL,
  `print_check_trailer` bit(1) DEFAULT NULL,
  `print_on_receipt` bit(1) DEFAULT NULL,
  `print_with_lookup` bit(1) DEFAULT NULL,
  `print_validation` bit(1) DEFAULT NULL,
  `print_endorsement` bit(1) DEFAULT NULL,
  `suppress_trailer` bit(1) DEFAULT NULL,
  `print_fiscal_cash` bit(1) DEFAULT NULL,
  `print_check` bit(1) DEFAULT NULL,
  `print_memo_check` bit(1) DEFAULT NULL,
  `print_shared_check` bit(1) DEFAULT NULL,
  `print_check_active_seat` bit(1) DEFAULT NULL,
  `reprint_check` bit(1) DEFAULT NULL,
  `period_class_id` int(11) DEFAULT NULL,
  `rvc_class_id` int(11) DEFAULT NULL,
  `extra_amout` float DEFAULT '0',
  `staff_hold` bit(1) NOT NULL DEFAULT b'0',
  `second_currency` bit(1) NOT NULL DEFAULT b'0',
  `shown` bit(1) DEFAULT b'1',
  `second_currency_symbol` varchar(20) NOT NULL DEFAULT '',
  `second_currency_exrate_base` float DEFAULT '1',
  `second_currency_exrate_value` float DEFAULT '0',
  `second_currency_exrate` float NOT NULL DEFAULT '0',
  `second_currency_min_val` float NOT NULL DEFAULT '0',
  `is_kiosk` bit(1) DEFAULT b'0',
  `kiosk_hint_1` varchar(200) DEFAULT NULL,
  `kiosk_hint_2` varchar(200) DEFAULT NULL,
  `kiosk_hint_3` varchar(200) DEFAULT NULL,
  `kiosk_icon` varchar(200) DEFAULT NULL,
  `kiosk_picture` varchar(200) DEFAULT NULL,
  `param1` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tender_media`
--
DROP TRIGGER IF EXISTS `trigger_tendermedia_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tendermedia_add` AFTER INSERT ON `tender_media` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tendermedia_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tendermedia_del` AFTER DELETE ON `tender_media` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tendermedia_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tendermedia_update` AFTER UPDATE ON `tender_media` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `tender_media_extra_setting`
--

DROP TABLE IF EXISTS `tender_media_extra_setting`;
CREATE TABLE `tender_media_extra_setting` (
  `setting_id` int(11) NOT NULL,
  `tender_media_id` int(11) NOT NULL,
  `extra_amout` float DEFAULT NULL,
  `effect_week` int(11) DEFAULT NULL,
  `effect_time` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tender_media_extra_setting`
--
DROP TRIGGER IF EXISTS `trigger_tendermedia_extra_setting_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tendermedia_extra_setting_add` AFTER INSERT ON `tender_media_extra_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tendermedia_extra_setting_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tendermedia_extra_setting_del` AFTER DELETE ON `tender_media_extra_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 1;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tendermedia_extra_setting_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tendermedia_extra_setting_update` AFTER UPDATE ON `tender_media_extra_setting` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `ticketbaider_config`
--

DROP TABLE IF EXISTS `ticketbaider_config`;
CREATE TABLE `ticketbaider_config` (
  `baseUrl` varchar(200) NOT NULL DEFAULT '' COMMENT '地址，带端口',
  `api_key` varchar(100) NOT NULL DEFAULT '' COMMENT 'nif',
  `api_secret` varchar(100) NOT NULL DEFAULT '' COMMENT 'coding',
  `complate_status` int(8) NOT NULL DEFAULT '0' COMMENT '0 开启 1 关闭',
  `create_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `ticketbaifiskaly_config`
--

DROP TABLE IF EXISTS `ticketbaifiskaly_config`;
CREATE TABLE `ticketbaifiskaly_config` (
  `baseUrl` varchar(200) NOT NULL DEFAULT '' COMMENT '地址，带端口',
  `api_key` varchar(100) NOT NULL DEFAULT '' COMMENT 'nif',
  `api_secret` varchar(100) NOT NULL DEFAULT '' COMMENT 'coding',
  `client_id` varchar(50) NOT NULL DEFAULT '' COMMENT '主机客户端id',
  `complate_status` int(8) NOT NULL DEFAULT '0' COMMENT '0 开启 1 关闭',
  `create_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `ticketbai_client`
--

DROP TABLE IF EXISTS `ticketbai_client`;
CREATE TABLE `ticketbai_client` (
  `order_head_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `nif` varchar(20) NOT NULL DEFAULT '' COMMENT '纳税人识别号',
  `nombre` varchar(150) NOT NULL DEFAULT '' COMMENT '法定名称',
  `domicilio` varchar(100) NOT NULL DEFAULT '' COMMENT '地址',
  `codigo_postal` varchar(8) NOT NULL DEFAULT '' COMMENT '邮编',
  `provincia` varchar(50) NOT NULL DEFAULT '' COMMENT '省份',
  `localidad` varchar(50) NOT NULL DEFAULT '' COMMENT '城市',
  `telefono` varchar(20) NOT NULL DEFAULT '' COMMENT '电话',
  `tipo_documento_extranjero` varchar(2) NOT NULL DEFAULT '00' COMMENT '00 表示不是外国人，外国文档类型，可以是"02" (NIF-IVA的欧盟国家), "03" (护照), "04" (居住国官方文件), "05" (居住证明), "06" (其他文档)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `tickets`
--

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `ticket_id` int(11) NOT NULL,
  `ticket_name` varchar(200) DEFAULT NULL,
  `amount` decimal(11,2) DEFAULT NULL,
  `remark` varchar(300) DEFAULT NULL,
  `short_name` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `tickets`
--
DROP TRIGGER IF EXISTS `trigger_tickets_add`;
DELIMITER $$
CREATE TRIGGER `trigger_tickets_add` AFTER INSERT ON `tickets` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tickets_del`;
DELIMITER $$
CREATE TRIGGER `trigger_tickets_del` AFTER DELETE ON `tickets` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_tickets_update`;
DELIMITER $$
CREATE TRIGGER `trigger_tickets_update` AFTER UPDATE ON `tickets` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_class`
--

DROP TABLE IF EXISTS `ticket_class`;
CREATE TABLE `ticket_class` (
  `ticket_class_id` int(11) NOT NULL DEFAULT '0',
  `ticket_class_name` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `ticket_class`
--
DROP TRIGGER IF EXISTS `trigger_ticket_class_add`;
DELIMITER $$
CREATE TRIGGER `trigger_ticket_class_add` AFTER INSERT ON `ticket_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_ticket_class_del`;
DELIMITER $$
CREATE TRIGGER `trigger_ticket_class_del` AFTER DELETE ON `ticket_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_ticket_class_update`;
DELIMITER $$
CREATE TRIGGER `trigger_ticket_class_update` AFTER UPDATE ON `ticket_class` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_config`
--

DROP TABLE IF EXISTS `ticket_config`;
CREATE TABLE `ticket_config` (
  `cr_res_id` varchar(50) NOT NULL DEFAULT '',
  `is_open` int(11) DEFAULT '1' COMMENT '0-正常，1-未注册/未开通',
  `auto_ticket` int(11) DEFAULT '0' COMMENT '-是否结账自动开票 0：否  1：是',
  `expiry_date` datetime DEFAULT NULL COMMENT '-到期时间',
  `channel` varchar(128) DEFAULT NULL,
  `url` varchar(100) DEFAULT NULL,
  `flag` int(11) DEFAULT NULL,
  `para` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_malaysia_client`
--

DROP TABLE IF EXISTS `ticket_malaysia_client`;
CREATE TABLE `ticket_malaysia_client` (
  `order_head_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `ID` varchar(36) NOT NULL DEFAULT 'EI00000000010',
  `SST` varchar(36) DEFAULT 'NA',
  `PASSPORT` varchar(36) DEFAULT 'NA',
  `address` varchar(200) DEFAULT 'NA',
  `name` varchar(301) DEFAULT 'General Public',
  `telephone` varchar(25) DEFAULT 'NA',
  `email` varchar(321) DEFAULT 'NA',
  `city` varchar(51) DEFAULT NULL,
  `city_code` varchar(51) DEFAULT NULL,
  `id_type` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_malaysia_config`
--

DROP TABLE IF EXISTS `ticket_malaysia_config`;
CREATE TABLE `ticket_malaysia_config` (
  `baseUrl` varchar(200) NOT NULL,
  `client_id` varchar(100) NOT NULL DEFAULT 'NA',
  `client_secret` varchar(100) NOT NULL DEFAULT 'NA',
  `ID` varchar(36) DEFAULT NULL,
  `BRN` varchar(36) DEFAULT NULL,
  `SST` varchar(36) DEFAULT NULL,
  `TTX` varchar(36) DEFAULT NULL,
  `PASSPORT` varchar(36) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `name` varchar(301) DEFAULT NULL,
  `telephone` varchar(25) DEFAULT NULL,
  `email` varchar(321) DEFAULT NULL,
  `city` varchar(51) DEFAULT NULL,
  `city_code` varchar(51) DEFAULT NULL,
  `sale_tax` float NOT NULL DEFAULT '0',
  `service_tax` float NOT NULL DEFAULT '0',
  `msic_code` varchar(6) NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  `complate_status` int(8) NOT NULL DEFAULT '0' COMMENT '1 开启 0 关闭'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_malaysia_order`
--

DROP TABLE IF EXISTS `ticket_malaysia_order`;
CREATE TABLE `ticket_malaysia_order` (
  `ticket_id` int(11) NOT NULL,
  `order_head_id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL DEFAULT '1',
  `ticket_type` int(11) NOT NULL,
  `ticket_status` int(11) NOT NULL,
  `ticket_date` datetime NOT NULL,
  `uuid` varchar(50) NOT NULL,
  `code_number` varchar(50) DEFAULT NULL,
  `submit_uuid` varchar(50) DEFAULT NULL,
  `cancel_uuid` varchar(50) NOT NULL,
  `cancel_reason` varchar(150) NOT NULL,
  `is_del` tinyint(4) NOT NULL,
  `issue_date` varchar(100) DEFAULT NULL,
  `ticket_amount` double NOT NULL,
  `pay_type` varchar(10) DEFAULT NULL,
  `sale_tax` float DEFAULT '0',
  `service_tax` float DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_order`
--

DROP TABLE IF EXISTS `ticket_order`;
CREATE TABLE `ticket_order` (
  `ticket_id` varchar(128) DEFAULT NULL,
  `ticket_status` int(11) DEFAULT '0' COMMENT '0：未开票 1：已开票',
  `order_head_id` int(11) NOT NULL DEFAULT '0',
  `check_id` int(11) NOT NULL DEFAULT '0',
  `ticket_info` varchar(1024) DEFAULT NULL COMMENT '发票信息',
  `ticket_date` datetime DEFAULT NULL,
  `channel` varchar(128) DEFAULT NULL,
  `ticket_num` varchar(64) DEFAULT NULL,
  `tax_type` int(11) DEFAULT '0',
  `retain_info` varchar(128) DEFAULT NULL COMMENT '保留信息',
  `ticket_amount` double DEFAULT '0' COMMENT '开票金额',
  `ticket_times` tinyint(4) DEFAULT '0' COMMENT '开票次数',
  `fechaRecepcionDGI` varchar(30) DEFAULT NULL COMMENT '巴拿马发票返回时间，格式参考：2024-05-27T01:47:22-05:00',
  `nroProtocoloAutorizacion` varchar(50) DEFAULT NULL COMMENT '巴拿马返回发票验证ID,格式参考：0000155596713-2-201520240000000000507497',
  `ticket_type` varchar(2) DEFAULT '01' COMMENT '01: 内部运营发票 02: 进口发票 03: 出口发票 04: 针对FE的贷记通知05: 针对FE的借记通知06: 一般贷记通知07: 一般借记通知08: 自由贸易区发票09: 退款',
  `is_del` tinyint(4) DEFAULT '0' COMMENT '0未取消，1取消',
  `reason` varchar(200) DEFAULT '' COMMENT '取消原因'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `ticket_relation`
--

DROP TABLE IF EXISTS `ticket_relation`;
CREATE TABLE `ticket_relation` (
  `ticket_class_id` int(11) NOT NULL DEFAULT '0',
  `ticket_id` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `ticket_relation`
--
DROP TRIGGER IF EXISTS `trigger_ticket_relation_add`;
DELIMITER $$
CREATE TRIGGER `trigger_ticket_relation_add` AFTER INSERT ON `ticket_relation` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_ticket_relation_del`;
DELIMITER $$
CREATE TRIGGER `trigger_ticket_relation_del` AFTER DELETE ON `ticket_relation` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_ticket_relation_update`;
DELIMITER $$
CREATE TRIGGER `trigger_ticket_relation_update` AFTER UPDATE ON `ticket_relation` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET tender_media = tender_media | 8;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `total_statistics`
--

DROP TABLE IF EXISTS `total_statistics`;
CREATE TABLE `total_statistics` (
  `id` int(11) NOT NULL DEFAULT '0',
  `total_checks` int(11) NOT NULL DEFAULT '0',
  `total_guests` int(11) NOT NULL DEFAULT '0',
  `install_date` datetime DEFAULT NULL,
  `db_version` varchar(10) DEFAULT NULL,
  `dayend_time` datetime DEFAULT NULL,
  `batch_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `user_dcb`
--

DROP TABLE IF EXISTS `user_dcb`;
CREATE TABLE `user_dcb` (
  `dcb_id` int(11) NOT NULL,
  `workstations_id` int(11) NOT NULL,
  `dcb_name` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `user_workstations`
--

DROP TABLE IF EXISTS `user_workstations`;
CREATE TABLE `user_workstations` (
  `workstations_id` int(11) NOT NULL,
  `pos_name` varchar(30) DEFAULT NULL,
  `revenue_center` int(11) DEFAULT NULL,
  `information_screen` int(11) DEFAULT NULL,
  `transaction_screen` int(11) DEFAULT NULL,
  `order_type` int(11) DEFAULT NULL,
  `auto_signout_delay` int(11) DEFAULT NULL,
  `check_start` int(11) DEFAULT '1000',
  `check_end` int(11) DEFAULT '9999',
  `check_num` int(11) DEFAULT '0',
  `enable_auto_signout` bit(1) DEFAULT NULL,
  `cash_drawers` int(11) DEFAULT NULL,
  `order_devices` int(11) DEFAULT '0',
  `customer_receipt_printer` int(11) DEFAULT NULL,
  `guest_check_printer` int(11) DEFAULT NULL,
  `journal_printer` int(11) DEFAULT NULL,
  `local_backup_printer` int(11) DEFAULT NULL,
  `report_printer` int(11) DEFAULT NULL,
  `peripheral_type` int(11) DEFAULT NULL,
  `connection_type` int(11) DEFAULT NULL,
  `opos_device_name` varchar(30) DEFAULT NULL,
  `opos_option` int(11) DEFAULT NULL,
  `com_port` int(11) DEFAULT NULL,
  `customer_display` int(11) DEFAULT '0',
  `ip_address` varchar(32) DEFAULT '',
  `subnet_mask` varchar(32) DEFAULT '',
  `print_class` int(11) DEFAULT NULL,
  `is_quickservice` bit(1) DEFAULT NULL,
  `takeout_printer` int(11) DEFAULT NULL,
  `a4_printer` int(11) DEFAULT NULL,
  `a4_line` int(11) NOT NULL DEFAULT '20',
  `kiosk_user` int(11) DEFAULT '999',
  `cashdro` varchar(32) DEFAULT NULL,
  `cashdro_user` varchar(32) DEFAULT NULL,
  `cashdro_pwd` varchar(32) DEFAULT NULL,
  `serverlisten_ip` varchar(32) DEFAULT '127.0.0.1',
  `cctv_ip` varchar(32) DEFAULT '127.0.0.1',
  `ecocash_id` varchar(128) DEFAULT NULL COMMENT 'eco找零机 设备id',
  `ecocash_url` varchar(64) DEFAULT '127.0.0.1:8080' COMMENT 'eco找零机ip:port',
  `ecocash_user` varchar(32) DEFAULT NULL,
  `ecocash_pwd` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 触发器 `user_workstations`
--
DROP TRIGGER IF EXISTS `trigger_workstation_add`;
DELIMITER $$
CREATE TRIGGER `trigger_workstation_add` AFTER INSERT ON `user_workstations` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_workstation_del`;
DELIMITER $$
CREATE TRIGGER `trigger_workstation_del` AFTER DELETE ON `user_workstations` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 4;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trigger_workstation_update`;
DELIMITER $$
CREATE TRIGGER `trigger_workstation_update` AFTER UPDATE ON `user_workstations` FOR EACH ROW BEGIN
	UPDATE webreport_setting SET rvc_center = rvc_center | 4;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- 表的结构 `vip_setting`
--

DROP TABLE IF EXISTS `vip_setting`;
CREATE TABLE `vip_setting` (
  `setting_id` int(11) NOT NULL,
  `ip_addr` varchar(60) DEFAULT NULL,
  `port` int(11) DEFAULT '8000',
  `res_id` varchar(20) DEFAULT NULL,
  `res_pwd` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `webreport_setting`
--

DROP TABLE IF EXISTS `webreport_setting`;
CREATE TABLE `webreport_setting` (
  `cr_url` varchar(200) DEFAULT NULL,
  `cr_port` int(11) NOT NULL DEFAULT '2003',
  `cr_res_id` varchar(50) DEFAULT NULL,
  `cr_res_pwd` varchar(50) DEFAULT NULL,
  `cr_last_endtime` datetime DEFAULT NULL,
  `cr_last_time` datetime DEFAULT NULL,
  `tender_media` int(11) DEFAULT '1',
  `major_group` int(11) DEFAULT '1',
  `family_group` int(11) DEFAULT '1',
  `rvc_center` int(11) DEFAULT '1',
  `periods` int(11) DEFAULT '1',
  `employee` int(11) DEFAULT '1',
  `menu_item` int(11) DEFAULT '1',
  `tables` int(11) DEFAULT '1',
  `res_info` int(11) DEFAULT '1',
  `soldout` int(11) DEFAULT '0',
  `soldoutp` int(11) DEFAULT '0',
  `preorder_time` datetime DEFAULT NULL,
  `edit_mode` int(11) DEFAULT '0',
  `register_server_url` varchar(100) DEFAULT NULL,
  `cr_last_basic_time` datetime DEFAULT NULL,
  `pos_key` varchar(1000) DEFAULT NULL,
  `token` varchar(100) DEFAULT NULL,
  `subcn` varchar(100) DEFAULT NULL,
  `suben` varchar(100) DEFAULT NULL,
  `expire` datetime DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  `subdetail` varchar(1000) DEFAULT NULL,
  `prodetail` varchar(1000) DEFAULT NULL,
  `service` varchar(1000) DEFAULT NULL,
  `b_chain` bit(1) DEFAULT b'0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `weight_units`
--

DROP TABLE IF EXISTS `weight_units`;
CREATE TABLE `weight_units` (
  `id` int(11) NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `unit_abbreviation` varchar(50) NOT NULL,
  `conversion_factor_to_kg` float NOT NULL,
  `converted_amount` float NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `worldline_config`
--

DROP TABLE IF EXISTS `worldline_config`;
CREATE TABLE `worldline_config` (
  `integration_key` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `worldpay`
--

DROP TABLE IF EXISTS `worldpay`;
CREATE TABLE `worldpay` (
  `id` int(11) NOT NULL,
  `port` int(11) NOT NULL DEFAULT '10000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `www_version`
--

DROP TABLE IF EXISTS `www_version`;
CREATE TABLE `www_version` (
  `major_version` int(11) NOT NULL,
  `menu_item` int(11) DEFAULT NULL,
  `condiment` int(11) DEFAULT NULL,
  `course` int(11) DEFAULT NULL,
  `res_info` int(11) DEFAULT NULL,
  `discount` int(11) DEFAULT NULL,
  `service` int(11) DEFAULT NULL,
  `tax` int(11) DEFAULT NULL,
  `payment` int(11) DEFAULT NULL,
  `tables` int(11) DEFAULT NULL,
  `employee` int(11) DEFAULT NULL,
  `employee_class` int(11) DEFAULT NULL,
  `order_default` int(11) DEFAULT NULL,
  `price_scheme` int(11) DEFAULT NULL,
  `consumption_limit` int(11) DEFAULT NULL,
  `reasons` int(11) DEFAULT NULL,
  `printer` int(11) DEFAULT NULL,
  `workstation` int(11) DEFAULT NULL,
  `major_group` int(11) DEFAULT NULL,
  `family_group` int(11) DEFAULT NULL,
  `periods` int(11) DEFAULT NULL,
  `rvc_center` int(11) DEFAULT NULL,
  `macros` int(11) DEFAULT NULL,
  `extend_1` int(11) DEFAULT NULL,
  `extend_2` int(11) DEFAULT NULL,
  `extend_3` int(11) DEFAULT NULL,
  `pad_data` int(11) DEFAULT NULL,
  `pad_picture` int(11) DEFAULT NULL,
  `soldout` int(11) NOT NULL DEFAULT '0',
  `tickets` int(11) NOT NULL DEFAULT '0',
  `extend_4` int(11) NOT NULL DEFAULT '0',
  `extend_5` int(11) NOT NULL DEFAULT '0',
  `extend_6` int(11) NOT NULL DEFAULT '0',
  `extend_7` int(11) NOT NULL DEFAULT '0',
  `extend_8` int(11) NOT NULL DEFAULT '0',
  `extend_9` int(11) NOT NULL DEFAULT '0',
  `extend_10` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `wx_server_setting`
--

DROP TABLE IF EXISTS `wx_server_setting`;
CREATE TABLE `wx_server_setting` (
  `wx_server_net` varchar(255) NOT NULL,
  `wx_server_local` varchar(255) DEFAULT NULL,
  `wx_mode` int(11) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `wx_setting`
--

DROP TABLE IF EXISTS `wx_setting`;
CREATE TABLE `wx_setting` (
  `id` int(10) UNSIGNED NOT NULL,
  `wx_server` varchar(255) NOT NULL DEFAULT '',
  `wx_wss` varchar(255) NOT NULL DEFAULT '',
  `version` int(10) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `zmachine`
--

DROP TABLE IF EXISTS `zmachine`;
CREATE TABLE `zmachine` (
  `id` int(10) UNSIGNED NOT NULL,
  `ZCTR` int(11) DEFAULT NULL,
  `OLDGT` double DEFAULT NULL,
  `GT` double DEFAULT NULL,
  `createdate` datetime DEFAULT NULL,
  `MachineNumber` varchar(50) DEFAULT NULL,
  `branchid` int(11) DEFAULT NULL,
  `GROSS_SALES_items` int(11) DEFAULT NULL,
  `GROSS_SALES_total` double DEFAULT NULL,
  `cashierNO` int(11) DEFAULT NULL,
  `cashiername` varchar(50) DEFAULT NULL,
  `Return_items` int(11) DEFAULT NULL,
  `Return_total` double DEFAULT NULL,
  `ORSTART` varchar(50) DEFAULT NULL,
  `OREND` varchar(50) DEFAULT NULL,
  `IsLock` int(11) DEFAULT NULL,
  `Discount` double DEFAULT NULL,
  `DiscountC` int(11) DEFAULT NULL,
  `VATExemptSales` double DEFAULT NULL,
  `SCDiscount` double DEFAULT NULL,
  `SCDiscountC` int(11) DEFAULT NULL,
  `PWDDiscount` double DEFAULT NULL,
  `PWDDiscountC` int(11) DEFAULT NULL,
  `CASH` double DEFAULT NULL,
  `CHANGE` double DEFAULT NULL,
  `CountNumber` int(11) DEFAULT NULL,
  `VATABLESALES` double DEFAULT NULL,
  `VAT` double DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 转储表的索引
--

--
-- 表的索引 `allergen`
--
ALTER TABLE `allergen`
  ADD PRIMARY KEY (`allergen_id`);

--
-- 表的索引 `baidu_region`
--
ALTER TABLE `baidu_region`
  ADD PRIMARY KEY (`area_id`);

--
-- 表的索引 `bargain_price_item`
--
ALTER TABLE `bargain_price_item`
  ADD PRIMARY KEY (`bargain_item_id`);

--
-- 表的索引 `cashbox_in_out`
--
ALTER TABLE `cashbox_in_out`
  ADD PRIMARY KEY (`in_out_id`);

--
-- 表的索引 `condiment_groups`
--
ALTER TABLE `condiment_groups`
  ADD PRIMARY KEY (`scomdiment_groups_id`);

--
-- 表的索引 `condiment_membership`
--
ALTER TABLE `condiment_membership`
  ADD PRIMARY KEY (`condiment_groups_id`,`menu_item_id`);

--
-- 表的索引 `consumption_limit`
--
ALTER TABLE `consumption_limit`
  ADD PRIMARY KEY (`consumption_limit_id`);

--
-- 表的索引 `country`
--
ALTER TABLE `country`
  ADD PRIMARY KEY (`country_id`);

--
-- 表的索引 `coupon_checkout`
--
ALTER TABLE `coupon_checkout`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `course`
--
ALTER TABLE `course`
  ADD PRIMARY KEY (`course_id`);

--
-- 表的索引 `course_detail`
--
ALTER TABLE `course_detail`
  ADD PRIMARY KEY (`detail_id`);

--
-- 表的索引 `course_group`
--
ALTER TABLE `course_group`
  ADD PRIMARY KEY (`course_group_id`);

--
-- 表的索引 `critical_operations`
--
ALTER TABLE `critical_operations`
  ADD PRIMARY KEY (`operation_id`);

--
-- 表的索引 `critical_operation_type`
--
ALTER TABLE `critical_operation_type`
  ADD PRIMARY KEY (`operation_type_id`);

--
-- 表的索引 `customer`
--
ALTER TABLE `customer`
  ADD PRIMARY KEY (`customer_id`);

--
-- 表的索引 `customer_consume`
--
ALTER TABLE `customer_consume`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `customer_display`
--
ALTER TABLE `customer_display`
  ADD PRIMARY KEY (`customer_display_id`,`com_port`);

--
-- 表的索引 `customer_order`
--
ALTER TABLE `customer_order`
  ADD UNIQUE KEY `order_head_id` (`order_head_id`);

--
-- 表的索引 `customer_order_notify`
--
ALTER TABLE `customer_order_notify`
  ADD UNIQUE KEY `order_head_id` (`order_head_id`);

--
-- 表的索引 `delivery_info`
--
ALTER TABLE `delivery_info`
  ADD PRIMARY KEY (`delivery_info_id`);

--
-- 表的索引 `descriptors_headers`
--
ALTER TABLE `descriptors_headers`
  ADD PRIMARY KEY (`descriptors_headers_id`);

--
-- 表的索引 `descriptors_menu_item_slu`
--
ALTER TABLE `descriptors_menu_item_slu`
  ADD PRIMARY KEY (`dmi_slu_id`);

--
-- 表的索引 `descriptors_trailers`
--
ALTER TABLE `descriptors_trailers`
  ADD PRIMARY KEY (`descriptors_trailers_id`);

--
-- 表的索引 `device_checkin`
--
ALTER TABLE `device_checkin`
  ADD PRIMARY KEY (`device_id`);

--
-- 表的索引 `device_info_cctv`
--
ALTER TABLE `device_info_cctv`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `discount_service`
--
ALTER TABLE `discount_service`
  ADD PRIMARY KEY (`discount_service_id`);

--
-- 表的索引 `divide_detail`
--
ALTER TABLE `divide_detail`
  ADD PRIMARY KEY (`divide_id`);

--
-- 表的索引 `ecocash_order`
--
ALTER TABLE `ecocash_order`
  ADD PRIMARY KEY (`order_head_id`,`check_id`);

--
-- 表的索引 `employee`
--
ALTER TABLE `employee`
  ADD PRIMARY KEY (`employee_id`);

--
-- 表的索引 `employee_class`
--
ALTER TABLE `employee_class`
  ADD PRIMARY KEY (`employee_class_id`);

--
-- 表的索引 `employee_dynamic`
--
ALTER TABLE `employee_dynamic`
  ADD PRIMARY KEY (`employee_id`);

--
-- 表的索引 `evaluation`
--
ALTER TABLE `evaluation`
  ADD PRIMARY KEY (`evaluation_id`);

--
-- 表的索引 `family_group`
--
ALTER TABLE `family_group`
  ADD PRIMARY KEY (`family_group_id`);

--
-- 表的索引 `history_card`
--
ALTER TABLE `history_card`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `history_day_end`
--
ALTER TABLE `history_day_end`
  ADD PRIMARY KEY (`history_day_end_id`);

--
-- 表的索引 `history_major_group`
--
ALTER TABLE `history_major_group`
  ADD PRIMARY KEY (`history_major_id`);

--
-- 表的索引 `history_messages`
--
ALTER TABLE `history_messages`
  ADD PRIMARY KEY (`history_message_id`);

--
-- 表的索引 `history_order_detail`
--
ALTER TABLE `history_order_detail`
  ADD KEY `idx_detailcheck` (`order_head_id`,`check_id`) USING BTREE,
  ADD KEY `idx_condiment` (`condiment_belong_item`) USING BTREE,
  ADD KEY `idx_detail` (`order_detail_id`) USING BTREE,
  ADD KEY `idx_kds` (`is_make`,`order_time`) USING BTREE,
  ADD KEY `idx_order_time` (`order_time`),
  ADD KEY `idx_return_time` (`return_time`);

--
-- 表的索引 `history_order_head`
--
ALTER TABLE `history_order_head`
  ADD KEY `idx_headcheck` (`order_head_id`,`check_id`) USING BTREE,
  ADD KEY `idx_order_end_time` (`order_end_time`) USING BTREE,
  ADD KEY `ids_order_start_time` (`order_start_time`);

--
-- 表的索引 `history_payment`
--
ALTER TABLE `history_payment`
  ADD PRIMARY KEY (`history_payment_id`);

--
-- 表的索引 `history_time_clock`
--
ALTER TABLE `history_time_clock`
  ADD PRIMARY KEY (`time_clock_id`);

--
-- 表的索引 `information_screens`
--
ALTER TABLE `information_screens`
  ADD PRIMARY KEY (`info_id`);

--
-- 表的索引 `inventory_setting`
--
ALTER TABLE `inventory_setting`
  ADD PRIMARY KEY (`setting_id`);

--
-- 表的索引 `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `order_head_id` (`order_head_id`,`check_id`) USING BTREE;

--
-- 表的索引 `invoices_japan`
--
ALTER TABLE `invoices_japan`
  ADD KEY `idx_headcheck` (`order_head_id`,`check_id`);

--
-- 表的索引 `invoices_spanish_setting`
--
ALTER TABLE `invoices_spanish_setting`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `item_main_group`
--
ALTER TABLE `item_main_group`
  ADD PRIMARY KEY (`main_group_id`,`second_group_id`);

--
-- 表的索引 `item_unit`
--
ALTER TABLE `item_unit`
  ADD PRIMARY KEY (`unit_id`);

--
-- 表的索引 `kiosk_item_main_group`
--
ALTER TABLE `kiosk_item_main_group`
  ADD PRIMARY KEY (`main_group_id`);

--
-- 表的索引 `kiosk_setting`
--
ALTER TABLE `kiosk_setting`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `lian_tuo_fu_key`
--
ALTER TABLE `lian_tuo_fu_key`
  ADD PRIMARY KEY (`appId`);

--
-- 表的索引 `macros`
--
ALTER TABLE `macros`
  ADD PRIMARY KEY (`macros_id`);

--
-- 表的索引 `major_group`
--
ALTER TABLE `major_group`
  ADD PRIMARY KEY (`major_group_id`);

--
-- 表的索引 `menu_item`
--
ALTER TABLE `menu_item`
  ADD PRIMARY KEY (`item_id`);

--
-- 表的索引 `menu_item_allergen`
--
ALTER TABLE `menu_item_allergen`
  ADD PRIMARY KEY (`item_id`,`allergen_id`),
  ADD KEY `allergen_id` (`allergen_id`);

--
-- 表的索引 `menu_item_class`
--
ALTER TABLE `menu_item_class`
  ADD PRIMARY KEY (`item_class_id`);

--
-- 表的索引 `menu_item_divide_setting`
--
ALTER TABLE `menu_item_divide_setting`
  ADD PRIMARY KEY (`setting_id`),
  ADD KEY `menu_item_id` (`menu_item_id`),
  ADD KEY `major_group_id` (`major_group_id`);

--
-- 表的索引 `menu_item_hit`
--
ALTER TABLE `menu_item_hit`
  ADD PRIMARY KEY (`hit_id`),
  ADD KEY `menu_item_id` (`menu_item_id`) USING BTREE,
  ADD KEY `hit_item_id` (`hit_item_id`) USING BTREE,
  ADD KEY `place_class` (`place_class`) USING BTREE;

--
-- 表的索引 `menu_item_hit_price`
--
ALTER TABLE `menu_item_hit_price`
  ADD PRIMARY KEY (`menu_item_id`);

--
-- 表的索引 `menu_item_multiple_link`
--
ALTER TABLE `menu_item_multiple_link`
  ADD KEY `menu_item_multiple_link_ibfk_1` (`menu_id`),
  ADD KEY `menu_item_multiple_link_ibfk_2` (`item_id_adult`),
  ADD KEY `menu_item_multiple_link_ibfk_3` (`item_id_child`);

--
-- 表的索引 `menu_item_multiple_setting`
--
ALTER TABLE `menu_item_multiple_setting`
  ADD PRIMARY KEY (`menu_id`);

--
-- 表的索引 `menu_item_pad_tag`
--
ALTER TABLE `menu_item_pad_tag`
  ADD PRIMARY KEY (`tag_id`,`item_id`),
  ADD KEY `item_id` (`item_id`);

--
-- 表的索引 `menu_item_slu_allergen`
--
ALTER TABLE `menu_item_slu_allergen`
  ADD PRIMARY KEY (`dmi_slu_id`,`allergen_id`),
  ADD KEY `allergen_id` (`allergen_id`);

--
-- 表的索引 `menu_item_takeout`
--
ALTER TABLE `menu_item_takeout`
  ADD PRIMARY KEY (`takeout_id`),
  ADD KEY `item_id` (`item_id`);

--
-- 表的索引 `menu_item_takeoutplatform`
--
ALTER TABLE `menu_item_takeoutplatform`
  ADD PRIMARY KEY (`tp_id`),
  ADD UNIQUE KEY `item_id` (`item_id`,`plat_site`);

--
-- 表的索引 `menu_item_takeout_tag`
--
ALTER TABLE `menu_item_takeout_tag`
  ADD UNIQUE KEY `tag_item` (`tag_id`,`item_id`) USING BTREE,
  ADD KEY `menu_item_takeout_tag_ibfk_2` (`item_id`);

--
-- 表的索引 `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`);

--
-- 表的索引 `messages_template_item`
--
ALTER TABLE `messages_template_item`
  ADD PRIMARY KEY (`message_tempitem_id`);

--
-- 表的索引 `msg_setting`
--
ALTER TABLE `msg_setting`
  ADD PRIMARY KEY (`setting_id`);

--
-- 表的索引 `muggle_keys`
--
ALTER TABLE `muggle_keys`
  ADD PRIMARY KEY (`muggle_id`),
  ADD UNIQUE KEY `muggle_id` (`muggle_id`) USING BTREE;

--
-- 表的索引 `online_setting`
--
ALTER TABLE `online_setting`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `operations_cctv`
--
ALTER TABLE `operations_cctv`
  ADD PRIMARY KEY (`operation_id`);

--
-- 表的索引 `operation_types_cctv`
--
ALTER TABLE `operation_types_cctv`
  ADD PRIMARY KEY (`operation_type_id`);

--
-- 表的索引 `order_default_group`
--
ALTER TABLE `order_default_group`
  ADD PRIMARY KEY (`order_default_groupid`);

--
-- 表的索引 `order_detail`
--
ALTER TABLE `order_detail`
  ADD PRIMARY KEY (`order_detail_id`);

--
-- 表的索引 `order_detail_default`
--
ALTER TABLE `order_detail_default`
  ADD PRIMARY KEY (`order_detail_default_id`);

--
-- 表的索引 `order_head`
--
ALTER TABLE `order_head`
  ADD PRIMARY KEY (`order_head_id`,`check_id`),
  ADD KEY `table_id` (`table_id`) USING BTREE;

--
-- 表的索引 `order_types`
--
ALTER TABLE `order_types`
  ADD PRIMARY KEY (`order_type_id`);

--
-- 表的索引 `pad_tag`
--
ALTER TABLE `pad_tag`
  ADD PRIMARY KEY (`tag_id`),
  ADD KEY `menu_id` (`menu_id`),
  ADD KEY `tag_id` (`tag_id`) USING BTREE;

--
-- 表的索引 `panama_clientes`
--
ALTER TABLE `panama_clientes`
  ADD PRIMARY KEY (`order_head_id`,`check_id`);

--
-- 表的索引 `panama_familia`
--
ALTER TABLE `panama_familia`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `panama_menu_item`
--
ALTER TABLE `panama_menu_item`
  ADD PRIMARY KEY (`menu_item_id`);

--
-- 表的索引 `panama_segmento`
--
ALTER TABLE `panama_segmento`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `parties`
--
ALTER TABLE `parties`
  ADD PRIMARY KEY (`party_id`);

--
-- 表的索引 `parties_type`
--
ALTER TABLE `parties_type`
  ADD PRIMARY KEY (`party_type_id`);

--
-- 表的索引 `party_default_remark`
--
ALTER TABLE `party_default_remark`
  ADD PRIMARY KEY (`remark_id`);

--
-- 表的索引 `party_item`
--
ALTER TABLE `party_item`
  ADD PRIMARY KEY (`party_item_id`);

--
-- 表的索引 `party_remark`
--
ALTER TABLE `party_remark`
  ADD PRIMARY KEY (`remark_id`);

--
-- 表的索引 `party_table`
--
ALTER TABLE `party_table`
  ADD PRIMARY KEY (`party_id`,`table_id`);

--
-- 表的索引 `payment`
--
ALTER TABLE `payment`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_headcheck` (`order_head_id`,`check_id`) USING BTREE;

--
-- 表的索引 `periods`
--
ALTER TABLE `periods`
  ADD PRIMARY KEY (`period_id`);

--
-- 表的索引 `period_class`
--
ALTER TABLE `period_class`
  ADD PRIMARY KEY (`period_class_id`);

--
-- 表的索引 `phone_history`
--
ALTER TABLE `phone_history`
  ADD PRIMARY KEY (`history_id`);

--
-- 表的索引 `pos_keys`
--
ALTER TABLE `pos_keys`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `practice`
--
ALTER TABLE `practice`
  ADD PRIMARY KEY (`practice_id`);

--
-- 表的索引 `practice_group`
--
ALTER TABLE `practice_group`
  ADD PRIMARY KEY (`practice_group_id`);

--
-- 表的索引 `pre_order`
--
ALTER TABLE `pre_order`
  ADD PRIMARY KEY (`pre_order_id`,`table_id`);

--
-- 表的索引 `pre_order_detail`
--
ALTER TABLE `pre_order_detail`
  ADD PRIMARY KEY (`preorder_detail_id`);

--
-- 表的索引 `price_scheme`
--
ALTER TABLE `price_scheme`
  ADD PRIMARY KEY (`menu_item_id`,`group_id`);

--
-- 表的索引 `price_scheme_group`
--
ALTER TABLE `price_scheme_group`
  ADD PRIMARY KEY (`group_id`);

--
-- 表的索引 `print_class`
--
ALTER TABLE `print_class`
  ADD PRIMARY KEY (`print_class_id`);

--
-- 表的索引 `print_devices`
--
ALTER TABLE `print_devices`
  ADD PRIMARY KEY (`print_device_id`);

--
-- 表的索引 `print_task`
--
ALTER TABLE `print_task`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `print_templates`
--
ALTER TABLE `print_templates`
  ADD PRIMARY KEY (`template_id`);

--
-- 表的索引 `reasons`
--
ALTER TABLE `reasons`
  ADD PRIMARY KEY (`reason_id`);

--
-- 表的索引 `report`
--
ALTER TABLE `report`
  ADD PRIMARY KEY (`report_id`);

--
-- 表的索引 `report_1`
--
ALTER TABLE `report_1`
  ADD PRIMARY KEY (`report_id`);

--
-- 表的索引 `report_2`
--
ALTER TABLE `report_2`
  ADD PRIMARY KEY (`report_id`);

--
-- 表的索引 `report_class`
--
ALTER TABLE `report_class`
  ADD PRIMARY KEY (`report_class_id`);

--
-- 表的索引 `report_class_1`
--
ALTER TABLE `report_class_1`
  ADD PRIMARY KEY (`report_class_id`);

--
-- 表的索引 `report_class_2`
--
ALTER TABLE `report_class_2`
  ADD PRIMARY KEY (`report_class_id`);

--
-- 表的索引 `restaurant`
--
ALTER TABLE `restaurant`
  ADD PRIMARY KEY (`restaurant_id`);

--
-- 表的索引 `restaurant_takeout_hours`
--
ALTER TABLE `restaurant_takeout_hours`
  ADD PRIMARY KEY (`rtake_hours_id`);

--
-- 表的索引 `restaurant_takeout_info`
--
ALTER TABLE `restaurant_takeout_info`
  ADD PRIMARY KEY (`rtake_info_id`);

--
-- 表的索引 `retail`
--
ALTER TABLE `retail`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `retail_detail`
--
ALTER TABLE `retail_detail`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `retail_paytype`
--
ALTER TABLE `retail_paytype`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `rvc_center`
--
ALTER TABLE `rvc_center`
  ADD PRIMARY KEY (`rvc_center_id`);

--
-- 表的索引 `rvc_class`
--
ALTER TABLE `rvc_class`
  ADD PRIMARY KEY (`rvc_class_id`);

--
-- 表的索引 `service_tip`
--
ALTER TABLE `service_tip`
  ADD PRIMARY KEY (`service_tip_id`);

--
-- 表的索引 `serving_period_class`
--
ALTER TABLE `serving_period_class`
  ADD PRIMARY KEY (`period_class_id`,`period`);

--
-- 表的索引 `serving_rvc_class`
--
ALTER TABLE `serving_rvc_class`
  ADD PRIMARY KEY (`rvc_class_id`,`rvc_center_id`);

--
-- 表的索引 `shift_info`
--
ALTER TABLE `shift_info`
  ADD PRIMARY KEY (`shift_id`);

--
-- 表的索引 `sms_templates`
--
ALTER TABLE `sms_templates`
  ADD PRIMARY KEY (`sms_id`);

--
-- 表的索引 `storage`
--
ALTER TABLE `storage`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `tables`
--
ALTER TABLE `tables`
  ADD PRIMARY KEY (`table_id`),
  ADD KEY `table_id` (`table_id`) USING BTREE;

--
-- 表的索引 `table_status`
--
ALTER TABLE `table_status`
  ADD PRIMARY KEY (`table_stauts_id`);

--
-- 表的索引 `takeout_tag`
--
ALTER TABLE `takeout_tag`
  ADD PRIMARY KEY (`tag_id`),
  ADD KEY `tag_id` (`tag_id`) USING BTREE;

--
-- 表的索引 `tax`
--
ALTER TABLE `tax`
  ADD PRIMARY KEY (`tax_id`);

--
-- 表的索引 `tax_details`
--
ALTER TABLE `tax_details`
  ADD KEY `order_head_id` (`order_head_id`) USING BTREE,
  ADD KEY `tax_group_id` (`tax_group_id`) USING BTREE,
  ADD KEY `check_id` (`check_id`) USING BTREE;

--
-- 表的索引 `tax_group`
--
ALTER TABLE `tax_group`
  ADD PRIMARY KEY (`tax_group_id`);

--
-- 表的索引 `tax_primary`
--
ALTER TABLE `tax_primary`
  ADD PRIMARY KEY (`tax_id`);

--
-- 表的索引 `tender_media`
--
ALTER TABLE `tender_media`
  ADD PRIMARY KEY (`tender_media_id`);

--
-- 表的索引 `tender_media_extra_setting`
--
ALTER TABLE `tender_media_extra_setting`
  ADD PRIMARY KEY (`setting_id`),
  ADD KEY `tender_media_id` (`tender_media_id`);

--
-- 表的索引 `ticketbai_client`
--
ALTER TABLE `ticketbai_client`
  ADD PRIMARY KEY (`order_head_id`,`check_id`);

--
-- 表的索引 `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`ticket_id`);

--
-- 表的索引 `ticket_class`
--
ALTER TABLE `ticket_class`
  ADD PRIMARY KEY (`ticket_class_id`);

--
-- 表的索引 `ticket_config`
--
ALTER TABLE `ticket_config`
  ADD PRIMARY KEY (`cr_res_id`);

--
-- 表的索引 `ticket_malaysia_client`
--
ALTER TABLE `ticket_malaysia_client`
  ADD PRIMARY KEY (`order_head_id`,`check_id`);

--
-- 表的索引 `ticket_malaysia_order`
--
ALTER TABLE `ticket_malaysia_order`
  ADD PRIMARY KEY (`ticket_id`);

--
-- 表的索引 `ticket_order`
--
ALTER TABLE `ticket_order`
  ADD PRIMARY KEY (`order_head_id`,`check_id`);

--
-- 表的索引 `ticket_relation`
--
ALTER TABLE `ticket_relation`
  ADD PRIMARY KEY (`ticket_class_id`,`ticket_id`);

--
-- 表的索引 `total_statistics`
--
ALTER TABLE `total_statistics`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `user_dcb`
--
ALTER TABLE `user_dcb`
  ADD PRIMARY KEY (`dcb_id`);

--
-- 表的索引 `user_workstations`
--
ALTER TABLE `user_workstations`
  ADD PRIMARY KEY (`workstations_id`);

--
-- 表的索引 `vip_setting`
--
ALTER TABLE `vip_setting`
  ADD PRIMARY KEY (`setting_id`);

--
-- 表的索引 `webreport_setting`
--
ALTER TABLE `webreport_setting`
  ADD PRIMARY KEY (`cr_port`);

--
-- 表的索引 `weight_units`
--
ALTER TABLE `weight_units`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `worldpay`
--
ALTER TABLE `worldpay`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `www_version`
--
ALTER TABLE `www_version`
  ADD PRIMARY KEY (`major_version`);

--
-- 表的索引 `wx_setting`
--
ALTER TABLE `wx_setting`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `zmachine`
--
ALTER TABLE `zmachine`
  ADD PRIMARY KEY (`id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `allergen`
--
ALTER TABLE `allergen`
  MODIFY `allergen_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `cashbox_in_out`
--
ALTER TABLE `cashbox_in_out`
  MODIFY `in_out_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `coupon_checkout`
--
ALTER TABLE `coupon_checkout`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `course_detail`
--
ALTER TABLE `course_detail`
  MODIFY `detail_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `critical_operations`
--
ALTER TABLE `critical_operations`
  MODIFY `operation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `customer`
--
ALTER TABLE `customer`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `customer_consume`
--
ALTER TABLE `customer_consume`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `delivery_info`
--
ALTER TABLE `delivery_info`
  MODIFY `delivery_info_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `device_info_cctv`
--
ALTER TABLE `device_info_cctv`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `divide_detail`
--
ALTER TABLE `divide_detail`
  MODIFY `divide_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `evaluation`
--
ALTER TABLE `evaluation`
  MODIFY `evaluation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `history_card`
--
ALTER TABLE `history_card`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `history_day_end`
--
ALTER TABLE `history_day_end`
  MODIFY `history_day_end_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `history_major_group`
--
ALTER TABLE `history_major_group`
  MODIFY `history_major_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `history_messages`
--
ALTER TABLE `history_messages`
  MODIFY `history_message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `history_payment`
--
ALTER TABLE `history_payment`
  MODIFY `history_payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `history_time_clock`
--
ALTER TABLE `history_time_clock`
  MODIFY `time_clock_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `inventory_setting`
--
ALTER TABLE `inventory_setting`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `item_unit`
--
ALTER TABLE `item_unit`
  MODIFY `unit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `menu_item_divide_setting`
--
ALTER TABLE `menu_item_divide_setting`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `menu_item_hit`
--
ALTER TABLE `menu_item_hit`
  MODIFY `hit_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `menu_item_takeoutplatform`
--
ALTER TABLE `menu_item_takeoutplatform`
  MODIFY `tp_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `muggle_keys`
--
ALTER TABLE `muggle_keys`
  MODIFY `muggle_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `online_setting`
--
ALTER TABLE `online_setting`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `operations_cctv`
--
ALTER TABLE `operations_cctv`
  MODIFY `operation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `order_detail`
--
ALTER TABLE `order_detail`
  MODIFY `order_detail_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `order_detail_default`
--
ALTER TABLE `order_detail_default`
  MODIFY `order_detail_default_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `order_head`
--
ALTER TABLE `order_head`
  MODIFY `order_head_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `parties`
--
ALTER TABLE `parties`
  MODIFY `party_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `party_default_remark`
--
ALTER TABLE `party_default_remark`
  MODIFY `remark_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `party_item`
--
ALTER TABLE `party_item`
  MODIFY `party_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `party_remark`
--
ALTER TABLE `party_remark`
  MODIFY `remark_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `payment`
--
ALTER TABLE `payment`
  MODIFY `payment_id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `phone_history`
--
ALTER TABLE `phone_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `practice`
--
ALTER TABLE `practice`
  MODIFY `practice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `pre_order`
--
ALTER TABLE `pre_order`
  MODIFY `pre_order_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `pre_order_detail`
--
ALTER TABLE `pre_order_detail`
  MODIFY `preorder_detail_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `print_task`
--
ALTER TABLE `print_task`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `print_templates`
--
ALTER TABLE `print_templates`
  MODIFY `template_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `report`
--
ALTER TABLE `report`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `report_1`
--
ALTER TABLE `report_1`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `report_2`
--
ALTER TABLE `report_2`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `retail`
--
ALTER TABLE `retail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `retail_detail`
--
ALTER TABLE `retail_detail`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `retail_paytype`
--
ALTER TABLE `retail_paytype`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `shift_info`
--
ALTER TABLE `shift_info`
  MODIFY `shift_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `storage`
--
ALTER TABLE `storage`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `tender_media_extra_setting`
--
ALTER TABLE `tender_media_extra_setting`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `ticket_malaysia_order`
--
ALTER TABLE `ticket_malaysia_order`
  MODIFY `ticket_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `user_dcb`
--
ALTER TABLE `user_dcb`
  MODIFY `dcb_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `vip_setting`
--
ALTER TABLE `vip_setting`
  MODIFY `setting_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `weight_units`
--
ALTER TABLE `weight_units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `worldpay`
--
ALTER TABLE `worldpay`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `zmachine`
--
ALTER TABLE `zmachine`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `menu_item_allergen`
--
ALTER TABLE `menu_item_allergen`
  ADD CONSTRAINT `menu_item_allergen_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_allergen_ibfk_2` FOREIGN KEY (`allergen_id`) REFERENCES `allergen` (`allergen_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_divide_setting`
--
ALTER TABLE `menu_item_divide_setting`
  ADD CONSTRAINT `divide_setting_ibfk_1` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `divide_setting_ibfk_2` FOREIGN KEY (`major_group_id`) REFERENCES `major_group` (`major_group_id`) ON DELETE CASCADE;

--
-- 限制表 `menu_item_hit`
--
ALTER TABLE `menu_item_hit`
  ADD CONSTRAINT `menu_item_hit_ibfk_1` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_hit_ibfk_2` FOREIGN KEY (`hit_item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_hit_ibfk_3` FOREIGN KEY (`place_class`) REFERENCES `rvc_class` (`rvc_class_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_multiple_link`
--
ALTER TABLE `menu_item_multiple_link`
  ADD CONSTRAINT `menu_item_multiple_link_ibfk_1` FOREIGN KEY (`menu_id`) REFERENCES `menu_item_multiple_setting` (`menu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_multiple_link_ibfk_2` FOREIGN KEY (`item_id_adult`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_multiple_link_ibfk_3` FOREIGN KEY (`item_id_child`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_pad_tag`
--
ALTER TABLE `menu_item_pad_tag`
  ADD CONSTRAINT `menu_item_pad_tag_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_pad_tag_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `pad_tag` (`tag_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_slu_allergen`
--
ALTER TABLE `menu_item_slu_allergen`
  ADD CONSTRAINT `menu_item_slu_allergen_ibfk_1` FOREIGN KEY (`dmi_slu_id`) REFERENCES `descriptors_menu_item_slu` (`dmi_slu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_slu_allergen_ibfk_2` FOREIGN KEY (`allergen_id`) REFERENCES `allergen` (`allergen_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_takeout`
--
ALTER TABLE `menu_item_takeout`
  ADD CONSTRAINT `menu_item_takeout_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_takeoutplatform`
--
ALTER TABLE `menu_item_takeoutplatform`
  ADD CONSTRAINT `menu_item_takeoutplatform_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `menu_item_takeout_tag`
--
ALTER TABLE `menu_item_takeout_tag`
  ADD CONSTRAINT `menu_item_takeout_tag_ibfk_1` FOREIGN KEY (`tag_id`) REFERENCES `takeout_tag` (`tag_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `menu_item_takeout_tag_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `menu_item` (`item_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `pad_tag`
--
ALTER TABLE `pad_tag`
  ADD CONSTRAINT `pad_tag_ibfk_1` FOREIGN KEY (`menu_id`) REFERENCES `menu_item_multiple_setting` (`menu_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- 限制表 `tender_media_extra_setting`
--
ALTER TABLE `tender_media_extra_setting`
  ADD CONSTRAINT `tender_media_extra_setting_ibfk_1` FOREIGN KEY (`tender_media_id`) REFERENCES `tender_media` (`tender_media_id`) ON DELETE CASCADE ON UPDATE CASCADE;
--
-- 数据库： `coolroid_train`
--
DROP DATABASE IF EXISTS `coolroid_train`;
CREATE DATABASE IF NOT EXISTS `coolroid_train` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `coolroid_train`;
--
-- 数据库： `test`
--
DROP DATABASE IF EXISTS `test`;
CREATE DATABASE IF NOT EXISTS `test` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `test`;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
