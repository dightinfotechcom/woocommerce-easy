/** @format */

import { sprintf, __ } from "@wordpress/i18n";
import { registerPaymentMethod } from "@woocommerce/blocks-registry";
import { decodeEntities } from "@wordpress/html-entities";
import { getSetting } from "@woocommerce/settings";
import { useState, useEffect } from "@wordpress/element";
import axios from "axios";
import Cookies from "js-cookie";
import { select } from "@wordpress/data";
const settings = getSetting("lyfepay_data", {});
const settings2 = getSetting("ach_data", {});

const defaultLabel = __("lyfePAY", "woo-gutenberg-products-block");
const defaultLabel2 = __("lyfePAY ACH", "woo-gutenberg-products-block");

const label = decodeEntities(settings.title) || defaultLabel;
const label2 = decodeEntities(settings2.title) || defaultLabel2;
const { CART_STORE_KEY } = window.wc.wcBlocksData;
const store = select(CART_STORE_KEY);

// const getCustomerIdFromCookies = () => {
// 	const customerResponse = Cookies.get("customer_response");
// 	if (customerResponse) {
// 		const parsedResponse = JSON.parse(customerResponse);
// 		return parsedResponse.client_token || "";
// 	}
// 	return "";
// };
// const customerID = getCustomerIdFromCookies();
// const getClientTokenFromCookies = () => {
// 	const paymentResponse = Cookies.get("payment_response");
// 	if (paymentResponse) {
// 		const parsedResponse = JSON.parse(paymentResponse);
// 		return parsedResponse.client_token || "";
// 	}
// 	return "";
// };
// const clientToken = getClientTokenFromCookies();
// console.log(clientToken);

const PaymentFields = () => {
	const [cards, setCards] = useState([]);
	const [useSavedCard, setUseSavedCard] = useState(false);
	const [showNewCardForm, setShowNewCardForm] = useState(true);
	const [error, setError] = useState(null);
	const [cardDetails, setCardDetails] = useState({});
	const [errors, setErrors] = useState({
		cardNumber: "",
		cardExpiry: "",
		cardCVC: "",
	});

	const handleCardChange = (event) => {
		setUseSavedCard(event.target.value === "exist");
		setShowNewCardForm(event.target.value === "new");
	};

	const handleInputChange = (e) => {
		const { name, value } = e.target;
		let formattedValue = value;
		let error = "";

		switch (name) {
			case "easymerchant-card-number":
				// Remove non-digit characters and limit to 16 digits
				formattedValue = value.replace(/\D/g, "").slice(0, 16);
				// Format the card number with spaces every 4 digits
				formattedValue = formattedValue.replace(/(.{4})/g, "$1 ").trim();
				// Check if the original value contains non-digit characters
				if (/\D/.test(value.replace(/\s/g, ""))) {
					error = "Card number must be numeric.";
				}
				break;

			case "easymerchant-card-expiry":
				formattedValue = value.replace(/\D/g, "");
				if (formattedValue.length > 2) {
					formattedValue = `${formattedValue.slice(
						0,
						2
					)} / ${formattedValue.slice(2, 6)}`;
				}
				if (
					!/^(0[1-9]|1[0-2])\/\d{4}$/.test(formattedValue) &&
					formattedValue.length === 7
				) {
					error = "Invalid date format.";
				} else {
					const [month, year] = formattedValue.split(" / ");
					const now = new Date();
					const inputDate = new Date(year, month - 1);
					if (inputDate < now) {
						error = "Expiry date cannot be in the past.";
					}
				}
				break;

			case "easymerchant-card-cvc":
				formattedValue = value.replace(/\D/g, "").slice(0, 3);
				if (/\D/.test(value)) {
					error = "CVC must be numeric.";
				} else if (value.length > 3) {
					error = "CVC cannot exceed 3 digits.";
				}
				break;

			case "easymerchant-card-holder-name":
				formattedValue = value;
				break;

			default:
				break;
		}

		setCardDetails({ ...cardDetails, [name]: formattedValue });
		setErrors({ ...errors, [name]: error });
	};

	if (error) {
		return <p>{error}</p>;
	}
	return (
		<div className="img-payment-fields">
			<>
				<input
					type="radio"
					id="exist_card"
					name="emwc_card"
					value="exist"
					onChange={handleCardChange}
				/>
				<label htmlFor="exist_card">Use saved cards</label>
				<input
					type="radio"
					id="new_card"
					name="emwc_card"
					value="new"
					onChange={handleCardChange}
					defaultChecked
				/>
				<label htmlFor="new_card">Use a new payment method</label>
			</>

			<div
				style={{ display: useSavedCard ? "none" : "block" }}
				id="img-payment-data">
				<fieldset>
					<p className="form-row form-row-wide">
						<label htmlFor="card-number">
							Card Number <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="easymerchant-card-number"
							name="easymerchant-card-number"
							className="input-text wc-credit-card-form-card-number"
							inputmode="numeric"
							placeholder="•••• •••• •••• ••••"
							autocomplete="cc-number"
							value={cardDetails["easymerchant-card-number"]}
							onChange={handleInputChange}
							required
						/>
						{errors["easymerchant-card-number"] && (
							<span className="error">
								{errors["easymerchant-card-number"]}
							</span>
						)}
					</p>
					<p className="form-row form-row-first">
						<label htmlFor="card-expiry">
							Expiry Date <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="easymerchant-card-expiry"
							name="easymerchant-card-expiry"
							placeholder="MM / YY"
							className="input-text wc-credit-card-form-card-expiry"
							inputmode="numeric"
							autocomplete="cc-exp"
							value={cardDetails["easymerchant-card-expiry"]}
							onChange={handleInputChange}
							required
						/>
						{errors["easymerchant-card-expiry"] && (
							<span className="error">
								{errors["easymerchant-card-expiry"]}
							</span>
						)}
					</p>
					<p className="form-row form-row-last">
						<label htmlFor="card-cvc">
							Card Code (CVC) <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="easymerchant-card-cvc"
							name="easymerchant-card-cvc"
							className="input-text wc-credit-card-form-card-cvc"
							inputmode="numeric"
							maxlength="4"
							value={cardDetails["easymerchant-card-cvc"]}
							onChange={handleInputChange}
							required
						/>
						{errors["easymerchant-card-cvc"] && (
							<span className="error">{errors["easymerchant-card-cvc"]}</span>
						)}
					</p>
					<p className="form-row form-row-wide">
						<label htmlFor="card-holder-name">
							Cardholder Name <span className="required">*</span>
						</label>
						<input
							type="text"
							id="easymerchant-card-holder-name"
							name="easymerchant-card-holder-name"
							className="input-text wc-credit-card-form-card-holder-name"
							maxlength="20"
							autocomplete="off"
							placeholder="Enter your name"
							value={cardDetails["easymerchant-card-holder-name"]}
							onChange={handleInputChange}
							required
						/>
					</p>

					<SavePaymentMethodCheckbox />
				</fieldset>
			</div>

			<div
				style={{ display: showNewCardForm ? "none" : "block" }}
				id="img-payment-data1">
				<fieldset>
					<p className="form-row form-row-wide">
						<label htmlFor="-ccard-number">
							Card Number <span className="required">*</span>
						</label>
						<div id="-ccard-number" className="input-text">
							<select name="ccard_id" style={{ padding: "5px" }}>
								<option value="">Select your option</option>
								{cards.map((card) => (
									<option key={card.card_id} value={card.card_id}>
										{card.card_brand_name} ending in {card.cc_last_4} (expires
										{card.cc_valid_thru})
									</option>
								))}
							</select>
						</div>
					</p>
				</fieldset>
			</div>
		</div>
	);
};
const SavePaymentMethodCheckbox = () => (
	<p className="form-row-save-card">
		<input
			type="checkbox"
			name="save_payment_method"
			id="save-payment-method"
		/>
		<label htmlFor="save-payment-method">
			Save payment information to my account for future purchases
		</label>
	</p>
);
const PaymentFields2 = () => {
	const [achDetails, setAchDetails] = useState({});
	const handldeAchInput = (e) => {
		const { name, value } = e.target;
		let formattedValue = value;
		setAchDetails((prevDetails) => {
			const updatedDetails = { ...prevDetails, [name]: formattedValue };
			return updatedDetails;
		});
	};

	return (
		<div className="ach-payment-fields">
			<div className="fieldset-input">
				<fieldset>
					<p className="form-row form-row-wide">
						<label htmlFor="ach-account-number">
							Account Number <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="ach-account-number"
							className="input-text ach-account-number"
							name="ach_account_number"
							placeholder="Account Number"
							value={achDetails["ach_account_number"]}
							onChange={handldeAchInput}
							required
						/>
					</p>
					<p className="form-row form-row-wide">
						<label htmlFor="ach-routing-number">
							Routing Number <span className="required">*</span>
						</label>
						<input
							type="tel"
							id="ach-routing-number"
							className="input-text ach-routing-number"
							name="ach_routing_number"
							placeholder="Routing Number"
							value={achDetails["ach_routing_number"]}
							onChange={handldeAchInput}
							required
						/>
					</p>
					<p className="form-row form-row-wide">
						<label htmlFor="ach-account-type">
							Account Type <span className="required">*</span>
						</label>
						<select
							type="tel"
							id="ach-account-type"
							className="input-text ach-account-type"
							name="ach_account_type"
							onChange={handldeAchInput}
							required>
							<option value="">Select</option>
							<option value="checking">Checking</option>
							<option value="savings">Savings</option>
							<option value="ledger">General Ledger</option>
						</select>
					</p>
				</fieldset>
			</div>
		</div>
	);
};

const Content = (props) => {
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup } = eventRegistration;

	// Function to set a cookie
	const setCookie = (name, value, days) => {
		const expires = new Date(Date.now() + days * 864e5).toUTCString();
		document.cookie =
			name +
			"=" +
			encodeURIComponent(value) +
			"; expires=" +
			expires +
			"; path=/";
	};

	const makePayment = async (paymentData) => {
		// Set the paymentPayload in cookies
		setCookie("paymentPayload", JSON.stringify(paymentData), 1);
	};

	useEffect(() => {
		if (typeof onPaymentSetup === "function") {
			const unsubscribe = onPaymentSetup(async () => {
				const customerData = store.getCustomerData();
				const cartTotals = store.getCartTotals();
				const billingAddress = customerData.billingAddress;
				const amountPayload = {
					amount: (cartTotals.total_price / 100).toFixed(2),
				};
				const expiryData = document.querySelector(
					"#easymerchant-card-expiry"
				).value;
				const [expiryMonth, expiryYear] = expiryData
					.split("/")
					.map((item) => item.trim());
				const paymentData = {
					payment_mode: "auth_and_capture",
					card_number: document.querySelector("#easymerchant-card-number")
						.value,
					exp_month: expiryMonth,
					exp_year: expiryYear,
					cvc: document.querySelector("#easymerchant-card-cvc").value,
					currency: cartTotals.currency_code,
					cardholder_name: document.querySelector(
						"#easymerchant-card-holder-name"
					).value,
					name: `${billingAddress.first_name} ${billingAddress.last_name}`,
					email: billingAddress.email,
					amount: amountPayload.amount,
					description: "Payment through easymerchant",
					customer_id: "",
				};
				return await makePayment(paymentData);
			});
			return () => {
				unsubscribe();
			};
		} else {
			console.error("onPaymentSetup is not a function");
		}
	}, [
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		onPaymentSetup,
	]);
	return <PaymentFields />;
};

const Content2 = (props) => {
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup } = eventRegistration;
	const setCookie = (name, value, days) => {
		const expires = new Date(Date.now() + days * 864e5).toUTCString();
		document.cookie =
			name +
			"=" +
			encodeURIComponent(value) +
			"; expires=" +
			expires +
			"; path=/";
	};
	const makePayment = async (paymentData) => {
		// Set the paymentPayload in cookies
		setCookie("ACHPaymentPayload", JSON.stringify(paymentData), 1);
	};

	useEffect(() => {
		const unsubscribe = onPaymentSetup(async () => {
			const customerData = store.getCustomerData();
			const billingAddress = customerData.billingAddress;
			const cartTotals = store.getCartTotals();
			const amountPayload = {
				amount: (cartTotals.total_price / 100).toFixed(2),
			};

			try {
				const paymentData = {
					amount: amountPayload.amount,
					name: `${billingAddress.first_name} ${billingAddress.last_name}`,
					description: "Woocommerce Payment through lyfePAY",
					routing_number: document.querySelector("#ach-routing-number").value,
					account_number: document.querySelector("#ach-account-number").value,
					account_type: document.querySelector("#ach-account-type").value,
					entry_class_code: "WEB",
				};
				return await makePayment(paymentData);
			} catch (error) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: error.message,
				};
			}
		});
		return () => {
			unsubscribe();
		};
	}, [
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		onPaymentSetup,
	]);

	return <PaymentFields2 />;
};
/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = (props) => {
	const { PaymentMethodLabel } = props.components;
	const imageStyle = {
		filter: "drop-shadow(2px 4px 6px black)",
	};
	const divStyle = {
		display: "flex",
		alignItems: "center",
		justifyContent: "space-between",
		width: "100%",
	};
	return (
		<div style={divStyle}>
			<PaymentMethodLabel text={label} />
			<div className="label-with-image">
				<img
					src="/wooeasy/wp-content/plugins/woocommerce-easymerchant/includes/assets/images/lyfecycle-payments-logo.png"
					alt="Label Image"
					style={imageStyle}
				/>
			</div>
		</div>
	);
};
const Label2 = (props) => {
	const { PaymentMethodLabel } = props.components;
	const imageStyle = {
		filter: "drop-shadow(2px 4px 6px black)",
	};
	const divStyle = {
		display: "flex",
		alignItems: "center",
		justifyContent: "space-between",
		width: "100%",
	};
	return (
		<div style={divStyle}>
			<PaymentMethodLabel text={label2} />
			<div className="label-with-image">
				<img
					src="/wooeasy/wp-content/plugins/woocommerce-easymerchant/includes/assets/images/lyfecycle-payments-logo.png"
					alt="Label Image"
					style={imageStyle}
				/>
			</div>
		</div>
	);
};

/**
 * Easymerchant payment method config object.
 */
const Easymerchant = {
	name: "easymerchant",
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		features: settings.supports,
	},
};
registerPaymentMethod(Easymerchant);

const ACH = {
	name: "ach-easymerchant",
	label: <Label2 />,
	content: <Content2 />,
	edit: <Content2 />,
	canMakePayment: () => true,
	ariaLabel: label2,
	supports: {
		features: settings2.supports,
	},
};
registerPaymentMethod(ACH);
