/** @format */

import { sprintf, __ } from "@wordpress/i18n";
import { registerPaymentMethod } from "@woocommerce/blocks-registry";
import { decodeEntities } from "@wordpress/html-entities";
import { getSetting } from "@woocommerce/settings";
import { useState, useEffect } from "@wordpress/element";
import axios from "axios";
import { select } from "@wordpress/data";
const settings = getSetting("lyfepay_data", {});
const settings2 = getSetting("ach_data", {});

const defaultLabel = __("lyfePAY", "woo-gutenberg-products-block");
const defaultLabel2 = __("ACH lyfePAY", "woo-gutenberg-products-block");

const label = decodeEntities(settings.title) || defaultLabel;
const label2 = decodeEntities(settings.title) || defaultLabel2;
const { CART_STORE_KEY } = window.wc.wcBlocksData;
// const { CHECKOUT_STORE_KEY } = window.wc.wcBlocksData;
// const { PAYMENT_STORE_KEY } = window.wc.wcBlocksData;
// const store = select(PAYMENT_STORE_KEY);
// const customerId = store.getCustomerId();
const store = select(CART_STORE_KEY);
const cartData = store.getCartData();

const cartTotals = store.getCartTotals();
const cartMeta = store.getCartMeta();
// const paymentMethodData = store.getPaymentMethodData();
// const shouldSavePaymentMethod = store.getShouldSavePaymentMethod();
// console.log(customerData);
const PaymentFields = () => {
	const [cards, setCards] = useState([]);
	const [useSavedCard, setUseSavedCard] = useState(false);
	const [showNewCardForm, setShowNewCardForm] = useState(true);
	const [customerId, setCustomerId] = useState(null);
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

/**
 * Content component
 */
const Content = (props) => {
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup } = eventRegistration;
	const headers = {
		"X-Api-Key": "d024a5f6f189be781ebd30d10",
		"X-Api-Secret": "d38a4eb39d46e3cf32f3d3217",
		"Content-Type": "application/json",
	};

	const createCustomer = async (customerPayload) => {
		try {
			// Arvind need to add tesmode / live mode check and update the URL accordingly
			const response = await axios.post(
				"https://stage-api.stage-easymerchant.io/api/v1/customers",
				customerPayload,
				{ headers }
			);
			if (response.data.success) {
				return response.data.customerId;
			} else {
				throw new Error(response.data.message);
			}
		} catch (error) {
			throw new Error(
				error.message || "There was an error creating the customer"
			);
		}
	};
	const makePayment = async (paymentData) => {
		try {
			const response = await axios.post(
				"https://stage-api.stage-easymerchant.io/api/v1/charge",
				paymentData,
				{ headers }
			);
			if (response.data.success) {
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: response.data.paymentMethodData,
					},
				};
			} else {
				throw new Error(response.data.message);
			}
		} catch (error) {
			return {
				type: emitResponse.responseTypes.ERROR,
				message: error.message || "There was an error processing your payment",
			};
		}
	};

	useEffect(() => {
		const unsubscribe = onPaymentSetup(async () => {
			const customerData = store.getCustomerData();
			const billingAddress = customerData.billingAddress;
			const customerPayload = {
				username: billingAddress.email,
				email: billingAddress.email,
				name: `${billingAddress.first_name} ${billingAddress.last_name}`,
				address: billingAddress.address_1,
				city: billingAddress.city,
				state: billingAddress.state,
				zip: billingAddress.postcode,
				country: billingAddress.country,
			};
			try {
				const customerId = await createCustomer(customerPayload);
				const paymentData = {
					payment_mode: "auth_and_capture",
					card_number: document.querySelector("#card-number").value,
					exp_month: document.querySelector("#card-expiry-month").value,
					exp_year: document.querySelector("#card-expiry-year").value,
					cvc: document.querySelector("#card-cvc").value,
					currency: "usd",
					cardholder_name: document.querySelector("#cardholder-name").value,
					name: customerData.name,
					email: customerData.email,
					amount: "10.00",
					description: "Payment through easymerchant",
					customer_id: customerId,
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

	return <PaymentFields />;
};
const Content2 = (props) => {
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup } = eventRegistration;
	const headers = {
		"X-Api-Key": "d024a5f6f189be781ebd30d10",
		"X-Api-Secret": "d38a4eb39d46e3cf32f3d3217",
		"Content-Type": "application/json",
	};
	const createCustomer = async (customerData) => {
		try {
			const response = await axios.post(
				"https://stage-api.stage-easymerchant.io/api/v1/customers",
				customerData,
				{ headers }
			);
			if (response.data.success) {
				return response.data.customerId;
			} else {
				throw new Error(response.data.message);
			}
		} catch (error) {
			throw new Error(
				error.message || "There was an error creating the customer"
			);
		}
	};
	const makePayment = async (paymentData) => {
		try {
			const response = await axios.post(
				"https://stage-api.stage-easymerchant.io/api/v1/charge",
				paymentData,
				{ headers }
			);
			if (response.data.success) {
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: response.data.paymentMethodData,
					},
				};
			} else {
				throw new Error(response.data.message);
			}
		} catch (error) {
			return {
				type: emitResponse.responseTypes.ERROR,
				message: error.message || "There was an error processing your payment",
			};
		}
	};

	useEffect(() => {
		const unsubscribe = onPaymentSetup(async () => {
			const customerData = {
				username: "arvind@arvind.com",
				email: "arvind1@arvind.com",
				name: "Dight",
				address: "Miran Tower",
				city: "Mohali",
				state: "Punjab",
				zip: "140306",
				country: "IN",
			};
			try {
				const customerId = await createCustomer(customerData);
				const paymentData = {
					amount: 2,
					name: "Test ACH",
					description: "test",
					routing_number: "061000227",
					account_number: "4761530001111118",
					account_type: "checking",
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
	return <PaymentMethodLabel text={label} />;
};
const Label2 = (props) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={label2} />;
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
